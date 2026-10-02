<?php
/**
 * Master Super Admin Dashboard for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/admin_db.php';

$adminUser = require_super_admin();
$pageTitle = 'Super Admin Dashboard';

$stats = admin_get_stats();
$recentClients = admin_get_clients('', '', '', 15, 0);

require_once __DIR__ . '/includes/admin_header.php';
?>

<!-- 1. HERO 1-CLICK CLIENT LOGIN & SEARCH -->
<div class="admin-card" style="margin-bottom: 20px;">
    <div style="padding: 22px 24px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; margin-bottom: 16px;">
            <div>
                <h2 style="font-size: 17px; font-weight: 700; color: #0f172a; margin: 0;">
                    Find Client &amp; 1-Click Login
                </h2>
                <p style="font-size: 13px; color: #64748b; margin-top: 3px;">
                    Search any client email or store name to instantly access their POS register and dashboard without a password.
                </p>
            </div>
            <div>
                <button type="button" onclick="openNewClientModal()" class="btn-adm btn-adm-secondary" style="font-size: 13px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add New Client Store
                </button>
            </div>
        </div>

        <!-- Interactive Fast Search Box -->
        <div style="background: #f8fafc; border: 1px solid var(--adm-border); border-radius: 8px; padding: 6px 8px 6px 12px; display: flex; align-items: center; gap: 10px;">
            <svg width="18" height="18" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input 
                type="text" 
                id="heroClientSearch" 
                placeholder="Type client email (e.g. prajapataman@gmail.com) or business name..." 
                style="flex: 1; background: transparent; border: none; color: #0f172a; font-size: 14px; outline: none; padding: 6px;"
                autocomplete="off"
            >
            <button type="button" onclick="executeHeroSearch()" class="btn-adm btn-adm-primary" style="padding: 8px 18px; font-size: 13px;">
                Find &amp; Login
            </button>
        </div>

        <!-- Live Results Container for Hero Search -->
        <div id="heroSearchResults" style="display: none; margin-top: 14px; background: #ffffff; color: #0f172a; border-radius: 8px; padding: 14px; border: 1px solid var(--adm-border); box-shadow: 0 4px 12px rgba(15,23,42,0.06);">
            <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.5px;">
                Matching Clients (Click to Login)
            </div>
            <div id="heroSearchList" style="display: flex; flex-direction: column; gap: 6px;"></div>
        </div>
    </div>
</div>

<!-- 2. HIGH-LEVEL KPI METRICS -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px;">
    <!-- Total Clients -->
    <div class="admin-card" style="margin-bottom: 0; padding: 18px 20px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="font-size: 11.5px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Total Clients</div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= number_format($stats['total_clients']) ?></div>
                <div style="font-size: 12px; color: #166534; font-weight: 500; margin-top: 4px;">
                    <?= number_format($stats['active_subscriptions']) ?> active accounts
                </div>
            </div>
            <div style="width: 38px; height: 38px; background: #f1f5f9; color: #475569; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
        </div>
    </div>

    <!-- Active Subscriptions -->
    <div class="admin-card" style="margin-bottom: 0; padding: 18px 20px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="font-size: 11.5px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Subscriptions</div>
                <div style="font-size: 24px; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= number_format($stats['active_subscriptions']) ?></div>
                <div style="font-size: 12px; color: #991b1b; font-weight: 500; margin-top: 4px;">
                    <?= number_format($stats['suspended_subscriptions']) ?> suspended / inactive
                </div>
            </div>
            <div style="width: 38px; height: 38px; background: #f1f5f9; color: #475569; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
    </div>
</div>

<!-- 3. CLIENT MANAGEMENT DIRECTORY -->
<div class="admin-card">
    <div class="admin-card-header">
        <div class="admin-card-title">
            <svg width="18" height="18" fill="none" stroke="#4f46e5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Manage Clients, Subscriptions &amp; Feature Access
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="<?= asset('admin/clients.php') ?>" class="btn-adm btn-adm-secondary btn-adm-sm">
                View All Clients (<?= $stats['total_clients'] ?>) &rarr;
            </a>
        </div>
    </div>

    <div class="adm-table-wrap">
        <table class="adm-table">
            <thead>
                <tr>
                    <th>Store / Business</th>
                    <th>Primary Owner &amp; Email</th>
                    <th>Subscription</th>
                    <th>Toggle Subscription</th>
                    <th>Plan</th>
                    <th>Features Entitlement</th>
                    <th style="text-align: right;">One-Click Login / Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentClients)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #64748b;">
                            No clients registered yet. Click <strong>+ Add New Client Store</strong> to register your first store.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentClients as $client): ?>
                        <?php
                        $subStatus = strtolower($client['subscription_status'] ?? 'active');
                        $isSubActive = $subStatus === 'active';
                        $bid = (int)$client['id'];
                        $userId = (int)($client['primary_user_id'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #0f172a; font-size: 14px;">
                                    <?= htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                    Org ID: <span style="font-family: 'JetBrains Mono', monospace; color: #4338ca;"><?= htmlspecialchars($client['organization_id'] ?? 'ORG-' . $bid, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #1e293b;">
                                    <?= htmlspecialchars($client['owner_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b;">
                                    <?= htmlspecialchars($client['owner_email'] ?? $client['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if (!empty($client['phone'])): ?>
                                    <div style="font-size: 11px; color: #94a3b8;"><?= htmlspecialchars($client['phone'], ENT_QUOTES, 'UTF-8') ?></div>
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
                                <a href="<?= asset('admin/client-features.php?id=' . $bid) ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" title="Click to customize enabled features">
                                    <span style="background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 700; padding: 4px 9px; border-radius: 6px; border: 1px solid #bfdbfe;">
                                        <?= (int)$client['enabled_features_count'] ?> / <?= (int)$client['total_features_count'] ?> Features
                                    </span>
                                    <span style="font-size: 11px; color: #4f46e5; font-weight: 600;">Edit &rarr;</span>
                                </a>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 8px;">
                                    <a href="<?= asset('admin/client-features.php?id=' . $bid) ?>" class="btn-adm btn-adm-secondary btn-adm-sm" title="Configure Client Features">
                                        Features
                                    </a>
                                    <a href="<?= asset('admin/impersonate.php?business_id=' . $bid) ?>" class="btn-adm btn-adm-login btn-adm-sm" title="Login directly as client with 1-click">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
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
            <input type="hidden" name="return_to" value="admin/dashboard.php">
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

<!-- MODAL: ADD NEW CLIENT STORE -->
<div id="newClientModal" class="adm-modal-backdrop">
    <div class="adm-modal">
        <div class="adm-modal-header">
            <h3 class="adm-modal-title">Create New Client Store</h3>
            <button type="button" onclick="closeNewClientModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <form id="newClientForm" onsubmit="handleCreateClient(event)">
            <div class="adm-modal-body" style="display: flex; flex-direction: column; gap: 14px;">
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Business / Store Name *</label>
                    <input type="text" name="business_name" required placeholder="e.g. Metro Fashion Retail" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                </div>
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Store Owner Full Name *</label>
                    <input type="text" name="name" required placeholder="e.g. John Doe" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Email Address *</label>
                        <input type="email" name="email" required placeholder="client@store.com" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                    </div>
                    <div>
                        <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Phone Number</label>
                        <input type="text" name="phone" placeholder="+91 9876543210" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                    </div>
                </div>
                <div>
                    <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">Initial Password</label>
                    <input type="text" name="password" value="Omniflow@2026" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                    <span style="font-size: 11px; color: #64748b;">Client can change this password after first login.</span>
                </div>
            </div>
            <div class="adm-modal-footer">
                <button type="button" onclick="closeNewClientModal()" class="btn-adm btn-adm-secondary">Cancel</button>
                <button type="submit" id="createClientSubmitBtn" class="btn-adm btn-adm-primary">Create Store</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Hero interactive client search & login
    const heroInput = document.getElementById('heroClientSearch');
    const heroResults = document.getElementById('heroSearchResults');
    const heroList = document.getElementById('heroSearchList');

    if (heroInput) {
        heroInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                executeHeroSearch();
            }
        });

        heroInput.addEventListener('input', function() {
            if (this.value.trim().length >= 2) {
                executeHeroSearch();
            } else {
                heroResults.style.display = 'none';
            }
        });
    }

    function executeHeroSearch() {
        const query = heroInput.value.trim();
        if (query.length < 2) {
            showAdmToast('Please enter at least 2 characters to search', 'error');
            return;
        }

        fetch('<?= asset("admin/api.php?action=search&q=") ?>' + encodeURIComponent(query))
            .then(r => r.json())
            .then(data => {
                if (!data.results || data.results.length === 0) {
                    heroList.innerHTML = `<div style="padding: 12px; text-align: center; color: #64748b; font-size: 13px;">No clients found matching "<strong>${escapeHtml(query)}</strong>"</div>`;
                    heroResults.style.display = 'block';
                    return;
                }

                let html = '';
                data.results.forEach(item => {
                    const subBadge = item.subscription_status === 'active' 
                        ? '<span class="status-badge active"><span class="status-dot"></span>Active</span>'
                        : '<span class="status-badge suspended"><span class="status-dot"></span>' + (item.subscription_status || 'Suspended') + '</span>';

                    html += `
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong style="font-size: 14px; color: #0f172a;">${escapeHtml(item.business_name)}</strong>
                                    ${subBadge}
                                    <span style="font-size: 11px; background: #e0e7ff; color: #3730a3; padding: 2px 6px; border-radius: 4px; font-weight: 700;">${escapeHtml(item.subscription_plan || 'Pro')}</span>
                                </div>
                                <div style="font-size: 12.5px; color: #475569;">
                                    User: <strong>${escapeHtml(item.user_name)}</strong> &bull; Email: <span style="color: #4f46e5; font-weight: 600;">${escapeHtml(item.user_email)}</span>
                                    ${item.user_phone ? ` &bull; Phone: ${escapeHtml(item.user_phone)}` : ''}
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <a href="<?= asset('admin/client-features.php?id=') ?>${item.business_id}" class="btn-adm btn-adm-secondary btn-adm-sm">
                                    Features
                                </a>
                                <a href="<?= asset('admin/impersonate.php?user_id=') ?>${item.user_id}" class="btn-adm btn-adm-login btn-adm-sm" style="font-size: 13px; padding: 8px 14px;">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                    1-Click Login As Client
                                </a>
                            </div>
                        </div>
                    `;
                });
                heroList.innerHTML = html;
                heroResults.style.display = 'block';
            })
            .catch(() => {
                showAdmToast('Failed to perform search', 'error');
            });
    }

    function openEditSubModal(client) {
        document.getElementById('editSubBizId').value = client.id;
        document.getElementById('editSubModalTitle').innerText = 'Edit Subscription: ' + client.name;
        document.getElementById('editSubStatus').value = client.subscription_status || 'active';
        document.getElementById('editSubPlan').value = client.subscription_plan || 'pro';
        if (client.subscription_expires_at) {
            const d = new Date(client.subscription_expires_at);
            document.getElementById('editSubExpires').value = d.toISOString().slice(0, 16);
        } else {
            document.getElementById('editSubExpires').value = '';
        }
        document.getElementById('editSubModal').classList.add('show');
    }

    function closeEditSubModal() {
        document.getElementById('editSubModal').classList.remove('show');
    }

    function openNewClientModal() {
        document.getElementById('newClientModal').classList.add('show');
    }

    function closeNewClientModal() {
        document.getElementById('newClientModal').classList.remove('show');
    }

    function handleCreateClient(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('createClientSubmitBtn');
        btn.disabled = true;
        btn.innerText = 'Creating...';

        const formData = new FormData(form);
        formData.append('action', 'create_client');

        fetch('<?= asset("admin/api.php") ?>', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = 'Create Store';
            if (data.success) {
                closeNewClientModal();
                showAdmToast('Client store created successfully!');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showAdmToast(data.error || 'Failed to create client store', 'error');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerText = 'Create Store';
            showAdmToast('Network error creating client', 'error');
        });
    }
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>

<?php
/**
 * Daily POS & Dashboard Alerts Modal Component
 * Displays Low Stock and 7-Day Unsold Products Notifications
 */
declare(strict_types=1);
require_once __DIR__ . '/products_db.php';
if (!isset($lowStockAlerts)) {
    $lowStockAlerts = get_pos_low_stock_alerts();
}
if (!isset($unsoldAlerts)) {
    $unsoldAlerts = get_pos_unsold_products_alerts(7);
}
$totalModalAlerts = count($lowStockAlerts) + count($unsoldAlerts);
$modalBizId = current_business_id();
?>
<style>
/* ================================================================
   DAILY ALERTS MODAL — Design
   ================================================================ */
#posDailyAlertsModal.modal-overlay{background:rgba(10,16,35,0.65);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);}
#posDailyAlertsModal .modal-box{max-width:660px;width:100%;max-height:92vh;display:flex;flex-direction:column;border-radius:20px;border:none;overflow:hidden;box-shadow:0 25px 60px rgba(10,16,35,0.22),0 8px 24px rgba(10,16,35,0.12);animation:alertModalIn 0.28s cubic-bezier(0.34,1.56,0.64,1);}
@keyframes alertModalIn{from{opacity:0;transform:translateY(18px) scale(0.96);}to{opacity:1;transform:translateY(0) scale(1);}}
/* Header */
#posDailyAlertsModal .dam-header{background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);padding:22px 24px 18px;display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-shrink:0;}
#posDailyAlertsModal .dam-header-icon{width:46px;height:46px;background:linear-gradient(135deg,#f97316,#ea580c);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;box-shadow:0 4px 14px rgba(234,88,12,0.4);}
#posDailyAlertsModal .dam-header-title{font-size:17px;font-weight:800;color:#fff;margin:0 0 3px;letter-spacing:-0.2px;}
#posDailyAlertsModal .dam-header-subtitle{font-size:12.5px;color:#94a3b8;margin:0;}
#posDailyAlertsModal .dam-close-btn{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);color:#94a3b8;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;line-height:1;transition:all 0.15s ease;flex-shrink:0;}
#posDailyAlertsModal .dam-close-btn:hover{background:rgba(255,255,255,0.15);color:#fff;}
/* Summary Strip */
#posDailyAlertsModal .dam-summary{display:flex;align-items:center;gap:12px;padding:14px 24px;background:#fff7ed;border-bottom:1px solid #fed7aa;flex-shrink:0;}
#posDailyAlertsModal .dam-summary.is-healthy{background:#f0fdf4;border-bottom-color:#bbf7d0;}
#posDailyAlertsModal .dam-summary-icon{font-size:20px;flex-shrink:0;}
#posDailyAlertsModal .dam-summary-text{font-size:13px;color:#92400e;line-height:1.5;}
#posDailyAlertsModal .dam-summary.is-healthy .dam-summary-text{color:#15803d;}
/* Tab Bar */
#posDailyAlertsModal .dam-tabs{display:flex;gap:4px;padding:12px 24px 0;background:#fff;border-bottom:1px solid #e2e8f0;flex-shrink:0;overflow-x:auto;scrollbar-width:none;}
#posDailyAlertsModal .dam-tabs::-webkit-scrollbar{display:none;}
#posDailyAlertsModal .dam-tab-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border:none;background:transparent;color:#64748b;font-size:13px;font-weight:600;cursor:pointer;border-radius:8px 8px 0 0;white-space:nowrap;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all 0.15s ease;}
#posDailyAlertsModal .dam-tab-btn:hover{color:#0f172a;background:#f8fafc;}
#posDailyAlertsModal .dam-tab-btn.active{color:#0f172a;border-bottom-color:#0f172a;background:transparent;}
#posDailyAlertsModal .dam-tab-badge{font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:20px;line-height:1.4;min-width:20px;text-align:center;background:#f1f5f9;color:#475569;}
#posDailyAlertsModal .dam-tab-badge.badge-red{background:#f1f5f9;color:#475569;}
#posDailyAlertsModal .dam-tab-badge.badge-indigo{background:#f1f5f9;color:#475569;}
#posDailyAlertsModal .dam-tab-badge.badge-slate{background:#f1f5f9;color:#475569;}
/* Body */
#posDailyAlertsModal .dam-body{flex:1;overflow-y:auto;padding:16px 24px 20px;background:#f8fafc;scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent;}
#posDailyAlertsModal .dam-body::-webkit-scrollbar{width:5px;}
#posDailyAlertsModal .dam-body::-webkit-scrollbar-track{background:transparent;}
#posDailyAlertsModal .dam-body::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px;}
#posDailyAlertsModal .dam-tab-pane{display:none;}
#posDailyAlertsModal .dam-tab-pane.active{display:block;}
#posDailyAlertsModal .dam-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:10px;padding-left:2px;}
/* Cards */
#posDailyAlertsModal .dam-card{background:#fff;border-radius:12px;padding:14px 18px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:14px;border:1px solid #e2e8f0;transition:box-shadow 0.15s ease;box-shadow:0 1px 3px rgba(0,0,0,0.03);}
#posDailyAlertsModal .dam-card:hover{box-shadow:0 3px 10px rgba(0,0,0,0.06);}
#posDailyAlertsModal .dam-card:last-child{margin-bottom:0;}
/* Only Out of Stock keeps the red left border from screenshot */
#posDailyAlertsModal .dam-card.card-danger{border-left:4px solid #ef4444;background:#fff;}
#posDailyAlertsModal .dam-card.card-warning{background:#fff;}
#posDailyAlertsModal .dam-card-icon{display:none;}
#posDailyAlertsModal .dam-card-thumb{width:46px;height:46px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
#posDailyAlertsModal .dam-product-img{width:100%;height:100%;object-fit:cover;display:block;}
#posDailyAlertsModal .dam-img-fallback{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#94a3b8;}
#posDailyAlertsModal .dam-card-info{flex:1;min-width:0;}
#posDailyAlertsModal .dam-card-name{font-size:14px;font-weight:700;color:#0f172a;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
#posDailyAlertsModal .dam-card-meta{font-size:12px;color:#64748b;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center;}
#posDailyAlertsModal .dam-card-notice{display:none;}
#posDailyAlertsModal .dam-meta-sep{color:#cbd5e1;}
#posDailyAlertsModal .dam-card-right{flex-shrink:0;text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:4px;}
#posDailyAlertsModal .dam-stock-badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
/* Out of Stock badge kept EXACTLY as in screenshot */
#posDailyAlertsModal .dam-stock-badge.badge-oos{background:#fecaca;color:#991b1b;}
/* All other badges: clean neutral gray, no color */
#posDailyAlertsModal .dam-stock-badge.badge-low{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
#posDailyAlertsModal .dam-stock-badge.badge-instock{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
#posDailyAlertsModal .dam-threshold{font-size:11px;color:#94a3b8;}
#posDailyAlertsModal .dam-days-inactive{font-size:11px;font-weight:500;color:#94a3b8;}
/* Empty State */
#posDailyAlertsModal .dam-empty{text-align:center;padding:36px 20px;}
#posDailyAlertsModal .dam-empty-icon{font-size:36px;margin-bottom:10px;}
#posDailyAlertsModal .dam-empty-title{font-size:15px;font-weight:700;color:#15803d;margin-bottom:4px;}
#posDailyAlertsModal .dam-empty-desc{font-size:12.5px;color:#64748b;}
/* Footer */
#posDailyAlertsModal .dam-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 24px;background:#fff;border-top:1px solid #e2e8f0;flex-shrink:0;flex-wrap:wrap;}
#posDailyAlertsModal .dam-dont-show-label{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#64748b;cursor:pointer;user-select:none;}
#posDailyAlertsModal .dam-dont-show-label input[type="checkbox"]{width:15px;height:15px;cursor:pointer;accent-color:#6366f1;}
#posDailyAlertsModal .dam-footer-actions{display:flex;align-items:center;gap:8px;}
#posDailyAlertsModal .dam-btn-secondary{font-size:12.5px;padding:8px 16px;border-radius:9px;border:1px solid #e2e8f0;background:#fff;color:#374151;font-weight:600;text-decoration:none;cursor:pointer;transition:all 0.15s ease;display:inline-flex;align-items:center;gap:5px;}
#posDailyAlertsModal .dam-btn-secondary:hover{background:#f8fafc;border-color:#cbd5e1;}
#posDailyAlertsModal .dam-btn-primary{font-size:12.5px;padding:8px 18px;border-radius:9px;border:none;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;font-weight:700;cursor:pointer;transition:all 0.15s ease;box-shadow:0 2px 8px rgba(99,102,241,0.3);}
#posDailyAlertsModal .dam-btn-primary:hover{background:linear-gradient(135deg,#4f46e5,#4338ca);box-shadow:0 4px 12px rgba(99,102,241,0.4);transform:translateY(-1px);}
/* Home Banner */
#posDailyAlertsModal .pos-home-alert-banner-v2{display:flex;align-items:stretch;margin-bottom:24px;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(234,88,12,0.12);flex-wrap:wrap;}
.pos-home-alert-banner-v2{display:flex;align-items:stretch;margin-bottom:24px;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(234,88,12,0.12);flex-wrap:wrap;}
.pos-home-alert-banner-v2 .phab-left{display:flex;align-items:center;gap:14px;flex:1;min-width:220px;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1px solid #fed7aa;border-right:none;padding:16px 20px;}
.pos-home-alert-banner-v2 .phab-right{background:linear-gradient(135deg,#ea580c,#c2410c);padding:16px 20px;display:flex;align-items:center;flex-shrink:0;}
.pos-home-alert-banner-v2 .phab-icon{width:44px;height:44px;background:linear-gradient(135deg,#f97316,#ea580c);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;box-shadow:0 4px 12px rgba(234,88,12,0.3);}
.pos-home-alert-banner-v2 .phab-text-title{font-size:14px;font-weight:800;color:#9a3412;margin-bottom:2px;}
.pos-home-alert-banner-v2 .phab-text-desc{font-size:12.5px;color:#c2410c;line-height:1.4;}
.pos-home-alert-banner-v2 .phab-btn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:#fff;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);border-radius:10px;padding:9px 16px;cursor:pointer;white-space:nowrap;transition:all 0.15s ease;}
.pos-home-alert-banner-v2 .phab-btn:hover{background:rgba(255,255,255,0.28);transform:translateY(-1px);}
@media(max-width:580px){
#posDailyAlertsModal .modal-box{border-radius:16px;max-height:96vh;}
#posDailyAlertsModal .dam-header{padding:18px 18px 14px;}
#posDailyAlertsModal .dam-body{padding:14px 16px 18px;}
#posDailyAlertsModal .dam-footer{padding:12px 16px;}
#posDailyAlertsModal .dam-tabs{padding:12px 16px 0;}
#posDailyAlertsModal .dam-summary{padding:12px 16px;}
.pos-home-alert-banner-v2 .phab-left{border-right:1px solid #fed7aa;border-bottom:none;width:100%;}
.pos-home-alert-banner-v2 .phab-right{width:100%;justify-content:center;}
}
</style>

<!-- DAILY ALERTS MODAL -->
<div class="modal-overlay" id="posDailyAlertsModal">
    <div class="modal-box">

        <!-- Header -->
        <div class="dam-header">
            <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:0;">
                <div class="dam-header-icon">&#x1F514;</div>
                <div>
                    <h3 class="dam-header-title">Daily Inventory &amp; Sales Notices</h3>
                    <p class="dam-header-subtitle">Stock level warnings and slow-moving product alerts</p>
                </div>
            </div>
            <button type="button" class="dam-close-btn" id="closePosAlertsModal" aria-label="Close">&times;</button>
        </div>

        <!-- Summary Strip -->
        <?php if ($totalModalAlerts > 0): ?>
        <div class="dam-summary">
            <div class="dam-summary-icon">&#x1F4E2;</div>
            <div class="dam-summary-text">
                <strong>Today's Notice &mdash; </strong>
                <?php if (count($lowStockAlerts) > 0 && count($unsoldAlerts) > 0): ?>
                    <strong><?= count($lowStockAlerts) ?> product(s)</strong> need restocking &amp; <strong><?= count($unsoldAlerts) ?> product(s)</strong> haven't sold in 7+ days.
                <?php elseif (count($lowStockAlerts) > 0): ?>
                    <strong><?= count($lowStockAlerts) ?> product(s)</strong> are at or below their reorder threshold.
                <?php else: ?>
                    <strong><?= count($unsoldAlerts) ?> product(s)</strong> have had zero sales in the past week.
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="dam-summary is-healthy">
            <div class="dam-summary-icon">&#x2705;</div>
            <div class="dam-summary-text"><strong>All Good!</strong> Stock is healthy and all products are selling normally.</div>
        </div>
        <?php endif; ?>

        <!-- Tab Bar -->
        <div class="dam-tabs">
            <button type="button" class="dam-tab-btn active" data-tab="low-stock">
                Low Stock
                <span class="dam-tab-badge"><?= count($lowStockAlerts) ?></span>
            </button>
            <button type="button" class="dam-tab-btn" data-tab="unsold-week">
                Not Sold in a Week
                <span class="dam-tab-badge"><?= count($unsoldAlerts) ?></span>
            </button>
            <button type="button" class="dam-tab-btn" data-tab="all-alerts">
                All Alerts
                <span class="dam-tab-badge"><?= $totalModalAlerts ?></span>
            </button>
        </div>

        <!-- Body -->
        <div class="dam-body">

            <!-- Tab 1: Low Stock -->
            <div class="dam-tab-pane active" id="paneLowStock">
                <?php if (empty($lowStockAlerts)): ?>
                    <div class="dam-empty">
                        <div class="dam-empty-title">No Low Stock Products</div>
                        <div class="dam-empty-desc">All active inventory quantities are above reorder thresholds.</div>
                    </div>
                <?php else: ?>
                    <div class="dam-section-label"><?= count($lowStockAlerts) ?> Product<?= count($lowStockAlerts) !== 1 ? 's' : '' ?> Need Restocking</div>
                    <?php foreach ($lowStockAlerts as $item):
                        $isDepleted = ((int)$item['stock_quantity'] <= 0);
                    ?>
                        <div class="dam-card <?= $isDepleted ? 'card-danger' : '' ?>">
                            <div class="dam-card-thumb">
                                <?php if (!empty($item['image_path'])): ?>
                                    <img src="<?= asset($item['image_path']) ?>" alt="<?= e($item['name']) ?>" class="dam-product-img" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="dam-img-fallback" style="display:none;">
                                        <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                    </div>
                                <?php else: ?>
                                    <div class="dam-img-fallback">
                                        <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="dam-card-info">
                                <div class="dam-card-name"><?= e($item['name']) ?></div>
                                <div class="dam-card-meta">
                                    <span>SKU: <strong><?= e($item['sku']) ?></strong></span>
                                </div>
                            </div>
                            <div class="dam-card-right">
                                <span class="dam-stock-badge <?= $isDepleted ? 'badge-oos' : 'badge-low' ?>">
                                    <?= $isDepleted ? 'Out of Stock' : 'Only ' . (int)$item['stock_quantity'] . ' Left' ?>
                                </span>
                                <span class="dam-threshold">Threshold: <?= (int)$item['low_stock_threshold'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Tab 2: Unsold Week -->
            <div class="dam-tab-pane" id="paneUnsoldWeek">
                <?php if (empty($unsoldAlerts)): ?>
                    <div class="dam-empty">
                        <div class="dam-empty-title">Healthy Sales Velocity</div>
                        <div class="dam-empty-desc">All products in stock have had purchases within the last 7 days.</div>
                    </div>
                <?php else: ?>
                    <div class="dam-section-label"><?= count($unsoldAlerts) ?> Product<?= count($unsoldAlerts) !== 1 ? 's' : '' ?> With Zero Sales in 7 Days</div>
                    <?php foreach ($unsoldAlerts as $item):
                        $daysInactive = (int)($item['days_inactive'] ?? 0);
                    ?>
                        <div class="dam-card">
                            <div class="dam-card-thumb">
                                <?php if (!empty($item['image_path'])): ?>
                                    <img src="<?= asset($item['image_path']) ?>" alt="<?= e($item['name']) ?>" class="dam-product-img" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="dam-img-fallback" style="display:none;">
                                        <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                    </div>
                                <?php else: ?>
                                    <div class="dam-img-fallback">
                                        <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="dam-card-info">
                                <div class="dam-card-name"><?= e($item['name']) ?></div>
                                <div class="dam-card-meta">
                                    <span>SKU: <strong><?= e($item['sku']) ?></strong></span>
                                </div>
                            </div>
                            <div class="dam-card-right">
                                <span class="dam-stock-badge badge-instock"><?= (int)$item['stock_quantity'] ?> in stock</span>
                                <span class="dam-days-inactive"><?= max(1, $daysInactive) ?>d inactive</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Tab 3: All Alerts -->
            <div class="dam-tab-pane" id="paneAllAlerts">
                <?php if ($totalModalAlerts === 0): ?>
                    <div class="dam-empty">
                        <div class="dam-empty-title">No Pending Alerts</div>
                        <div class="dam-empty-desc">Inventory is healthy and rotating normally.</div>
                    </div>
                <?php else: ?>
                    <?php if (!empty($lowStockAlerts)): ?>
                        <div class="dam-section-label" style="margin-top:4px;">Low Stock (<?= count($lowStockAlerts) ?>)</div>
                        <?php foreach ($lowStockAlerts as $item):
                            $isDepleted = ((int)$item['stock_quantity'] <= 0);
                        ?>
                            <div class="dam-card <?= $isDepleted ? 'card-danger' : '' ?>">
                                <div class="dam-card-thumb">
                                    <?php if (!empty($item['image_path'])): ?>
                                        <img src="<?= asset($item['image_path']) ?>" alt="<?= e($item['name']) ?>" class="dam-product-img" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="dam-img-fallback" style="display:none;">
                                            <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                        </div>
                                    <?php else: ?>
                                        <div class="dam-img-fallback">
                                            <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="dam-card-info">
                                    <div class="dam-card-name"><?= e($item['name']) ?></div>
                                    <div class="dam-card-meta">
                                        <span>SKU: <strong><?= e($item['sku']) ?></strong></span>
                                    </div>
                                </div>
                                <div class="dam-card-right">
                                    <span class="dam-stock-badge <?= $isDepleted ? 'badge-oos' : 'badge-low' ?>">
                                        <?= $isDepleted ? 'Out of Stock' : 'Only ' . (int)$item['stock_quantity'] . ' Left' ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($unsoldAlerts)): ?>
                        <div class="dam-section-label" style="margin-top:14px;">Not Sold in 7 Days (<?= count($unsoldAlerts) ?>)</div>
                        <?php foreach ($unsoldAlerts as $item): ?>
                            <div class="dam-card">
                                <div class="dam-card-thumb">
                                    <?php if (!empty($item['image_path'])): ?>
                                        <img src="<?= asset($item['image_path']) ?>" alt="<?= e($item['name']) ?>" class="dam-product-img" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="dam-img-fallback" style="display:none;">
                                            <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                        </div>
                                    <?php else: ?>
                                        <div class="dam-img-fallback">
                                            <svg width="20" height="20" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11M4 7v10l8 4"/></svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="dam-card-info">
                                    <div class="dam-card-name"><?= e($item['name']) ?></div>
                                    <div class="dam-card-meta">
                                        <span>SKU: <strong><?= e($item['sku']) ?></strong></span>
                                    </div>
                                </div>
                                <div class="dam-card-right">
                                    <span class="dam-stock-badge badge-instock"><?= (int)$item['stock_quantity'] ?> in stock</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div><!-- /.dam-body -->

        <!-- Footer -->
        <div class="dam-footer">
            <label class="dam-dont-show-label">
                <input type="checkbox" id="dontShowAlertAgainToday" checked>
                <span>Don't auto-show again today</span>
            </label>
            <div class="dam-footer-actions">
                <a href="<?= asset('inventory.php') ?>" class="dam-btn-secondary">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 10V11"/></svg>
                    Manage Inventory
                </a>
                <button type="button" class="dam-btn-primary" id="dismissPosAlertsModal">
                    Got it &nbsp;&#x2713;
                </button>
            </div>
        </div>

    </div>
</div>

<script>
(function() {
    var modal     = document.getElementById('posDailyAlertsModal');
    var closeBtn  = document.getElementById('closePosAlertsModal');
    var dismissBtn= document.getElementById('dismissPosAlertsModal');
    var chk       = document.getElementById('dontShowAlertAgainToday');
    var bizId     = '<?= (string)$modalBizId ?>';
    var total     = <?= (int)$totalModalAlerts ?>;
    var LS_KEY    = 'pos_daily_alert_date_' + bizId;

    function openModal()  { if (modal) modal.classList.add('open'); }
    function closeModal() {
        if (!modal) return;
        modal.classList.remove('open');
        if (chk && chk.checked) {
            try { localStorage.setItem(LS_KEY, new Date().toISOString().slice(0,10)); } catch(e){}
        }
    }

    document.querySelectorAll('#openPosAlertsModalBtn,#openHeaderAlertsBtn,#openHomeAlertsBannerBtn,.trigger-alerts-modal-btn')
        .forEach(function(el){ el.addEventListener('click', openModal); });

    if (closeBtn)   closeBtn.addEventListener('click', closeModal);
    if (dismissBtn) dismissBtn.addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });

    document.querySelectorAll('.dam-tab-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.dam-tab-btn').forEach(function(b){ b.classList.remove('active'); });
            this.classList.add('active');
            var map = {'low-stock':'paneLowStock','unsold-week':'paneUnsoldWeek','all-alerts':'paneAllAlerts'};
            document.querySelectorAll('.dam-tab-pane').forEach(function(p){ p.classList.remove('active'); });
            var pane = document.getElementById(map[this.dataset.tab]);
            if (pane) pane.classList.add('active');
        });
    });

    if (total > 0 && modal) {
        var today = new Date().toISOString().slice(0,10);
        try {
            if (localStorage.getItem(LS_KEY) !== today) {
                setTimeout(function(){ modal.classList.add('open'); }, 600);
            }
        } catch(e){}
    }
})();
</script>
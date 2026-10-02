<?php
/**
 * Owner control panel markup (included from dashboard.php).
 * Expects: $ownerSnap (array from owner_dashboard_snapshot), helper e(), asset()
 */
declare(strict_types=1);

if (!isset($ownerSnap) || !is_array($ownerSnap)) {
    return;
}
$s = $ownerSnap;
$meta = is_array($s['meta'] ?? null) ? $s['meta'] : [];
$businessName = trim((string) ($meta['business_name'] ?? (defined('APP_NAME') ? APP_NAME : 'POS')));
$reportDays = (int) ($meta['report_days'] ?? 30);
$currency = (string) ($meta['currency_symbol'] ?? '₹');
$sales = $s['sales'] ?? [];
$alerts = $s['alerts'] ?? [];
$maxChannel = 0.0;
foreach ($s['channels'] ?? [] as $ch) {
    $maxChannel = max($maxChannel, (float) ($ch['revenue'] ?? 0));
}
?>
<section class="od-panel" aria-label="Owner control panel">
    <div class="od-head">
        <div>
            <h2 class="od-title"><?= e($businessName) ?> — Owner Control</h2>
            <p class="od-sub">Live data for your business (last <?= (int) $reportDays ?> days on channel &amp; payment charts). Open linked modules for full detail.</p>
        </div>
        <div class="od-quick">
            <a href="<?= asset('reports.php') ?>" class="od-pill">Reports</a>
            <a href="<?= asset('registers.php') ?>" class="od-pill">Cash drawer</a>
            <a href="<?= asset('purchase-entry.php') ?>" class="od-pill">Purchase entry</a>
            <a href="<?= asset('fulfillment.php') ?>" class="od-pill">Dispatch</a>
        </div>
    </div>

    <?php
    $hasAlerts = (($alerts['low_stock'] ?? 0) > 0)
        || (($alerts['out_of_stock'] ?? 0) > 0)
        || (($alerts['cash_mismatch_today'] ?? 0) > 0)
        || (($alerts['pending_purchase_cost'] ?? 0) > 0)
        || (($alerts['pending_fulfillment'] ?? 0) > 0)
        || (($alerts['pending_payments'] ?? 0) > 0);
    ?>
    <?php if ($hasAlerts): ?>
    <div class="od-alerts">
        <?php if (($alerts['low_stock'] ?? 0) > 0): ?>
            <a class="od-alert od-alert-warn" href="<?= asset('inventory.php') ?>">Low stock: <?= (int) $alerts['low_stock'] ?></a>
        <?php endif; ?>
        <?php if (($alerts['out_of_stock'] ?? 0) > 0): ?>
            <a class="od-alert od-alert-danger" href="<?= asset('inventory.php') ?>">Out of stock: <?= (int) $alerts['out_of_stock'] ?></a>
        <?php endif; ?>
        <?php if (($alerts['pending_purchase_cost'] ?? 0) > 0): ?>
            <a class="od-alert od-alert-warn" href="<?= asset('purchase-entry.php') ?>">Owner cost pending: <?= (int) $alerts['pending_purchase_cost'] ?> PUR</a>
        <?php endif; ?>
        <?php if (($alerts['cash_mismatch_today'] ?? 0) > 0): ?>
            <a class="od-alert od-alert-danger" href="<?= asset('reports.php?type=register-shifts') ?>">Cash mismatch today: <?= (int) $alerts['cash_mismatch_today'] ?> shift(s)</a>
        <?php endif; ?>
        <?php if (($alerts['pending_fulfillment'] ?? 0) > 0): ?>
            <a class="od-alert od-alert-info" href="<?= asset('fulfillment.php') ?>">Pending dispatch: <?= (int) $alerts['pending_fulfillment'] ?></a>
        <?php endif; ?>
        <?php if (($alerts['pending_payments'] ?? 0) > 0): ?>
            <span class="od-alert od-alert-info">Pending payments: <?= (int) $alerts['pending_payments'] ?> orders</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="od-kpi-grid">
        <div class="od-kpi">
            <span class="od-kpi-label">Today</span>
            <span class="od-kpi-val"><?= e($currency) ?><?= number_format((float) ($sales['today']['revenue'] ?? 0), 2) ?></span>
            <span class="od-kpi-meta"><?= (int) ($sales['today']['orders'] ?? 0) ?> orders</span>
        </div>
        <div class="od-kpi">
            <span class="od-kpi-label">This week</span>
            <span class="od-kpi-val"><?= e($currency) ?><?= number_format((float) ($sales['week']['revenue'] ?? 0), 2) ?></span>
            <span class="od-kpi-meta"><?= (int) ($sales['week']['orders'] ?? 0) ?> orders (7d)</span>
        </div>
        <div class="od-kpi">
            <span class="od-kpi-label">This month</span>
            <span class="od-kpi-val"><?= e($currency) ?><?= number_format((float) ($sales['month']['revenue'] ?? 0), 2) ?></span>
            <span class="od-kpi-meta"><?= (int) ($sales['month']['orders'] ?? 0) ?> orders</span>
        </div>
        <div class="od-kpi">
            <span class="od-kpi-label">Open drawers</span>
            <span class="od-kpi-val"><?= (int) ($s['registers']['open_sessions'] ?? 0) ?></span>
            <span class="od-kpi-meta"><a href="<?= asset('registers.php') ?>">Manage registers</a></span>
        </div>
    </div>

    <div class="od-grid-2">
        <div class="od-card">
            <h3 class="od-card-title">Sales by channel <span class="od-muted">(<?= (int) $reportDays ?> days)</span></h3>
            <?php if (empty($s['channels'])): ?>
                <p class="od-empty">No channel data yet.</p>
            <?php else: ?>
                <ul class="od-bar-list">
                    <?php foreach ($s['channels'] as $ch): ?>
                        <?php $pct = $maxChannel > 0 ? min(100, round(((float) $ch['revenue'] / $maxChannel) * 100)) : 0; ?>
                        <li>
                            <div class="od-bar-row">
                                <span><?= e((string) $ch['label']) ?></span>
                                <span><?= e($currency) ?><?= number_format((float) $ch['revenue'], 0) ?> · <?= (int) $ch['orders'] ?></span>
                            </div>
                            <div class="od-bar-track"><div class="od-bar-fill" style="width:<?= $pct ?>%"></div></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="od-card">
            <h3 class="od-card-title">Store-wise sales <span class="od-muted">(this month)</span></h3>
            <?php if (empty($s['outlets'])): ?>
                <p class="od-empty">No outlet breakdown yet.</p>
            <?php else: ?>
                <table class="od-table">
                    <thead><tr><th>Store</th><th>Orders</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['outlets'] as $o): ?>
                        <tr>
                            <td><?= e((string) $o['outlet_name']) ?></td>
                            <td><?= (int) $o['orders'] ?></td>
                            <td><?= e($currency) ?><?= number_format((float) $o['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="od-foot"><a href="<?= asset('reports.php?type=sales-by-outlet') ?>">Full outlet report →</a></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="od-grid-3">
        <div class="od-card">
            <h3 class="od-card-title">Payments <span class="od-muted">(<?= (int) $reportDays ?>d)</span></h3>
            <?php if (empty($s['payments'])): ?>
                <p class="od-empty">No payments recorded.</p>
            <?php else: ?>
                <ul class="od-simple-list">
                    <?php foreach ($s['payments'] as $p): ?>
                        <li><span><?= e((string) $p['label']) ?></span><strong><?= e($currency) ?><?= number_format((float) $p['revenue'], 0) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="od-card">
            <h3 class="od-card-title">Orders &amp; dispatch <span class="od-muted">(30d)</span></h3>
            <ul class="od-simple-list">
                <?php
                $ful = $s['fulfillment'] ?? [];
                foreach ($ful as $statusKey => $count):
                    if ((int) $count === 0) {
                        continue;
                    }
                ?>
                    <li><span><?= e(owner_dashboard_format_channel_label((string) $statusKey)) ?></span><strong><?= (int) $count ?></strong></li>
                <?php endforeach; ?>
                <?php
                $fulHasData = false;
                foreach ($ful as $fc) {
                    if ((int) $fc > 0) {
                        $fulHasData = true;
                        break;
                    }
                }
                if (!$fulHasData):
                ?>
                    <li><span class="od-muted">No fulfillment activity in range</span></li>
                <?php endif; ?>
            </ul>
            <p class="od-foot"><a href="<?= asset('fulfillment.php') ?>">Shipments →</a> · <a href="<?= asset('returns.php') ?>">Returns →</a></p>
        </div>

        <div class="od-card">
            <h3 class="od-card-title">Purchase</h3>
            <ul class="od-simple-list">
                <li><span>Pending owner cost</span><strong><?= (int) ($s['purchase']['pending_cost'] ?? 0) ?></strong></li>
                <li><span>Barcode pending</span><strong><?= (int) ($s['purchase']['barcode_pending'] ?? 0) ?></strong></li>
                <li><span>Completed this month</span><strong><?= (int) ($s['purchase']['finalized_month'] ?? 0) ?></strong></li>
                <li><span>Transfers in progress</span><strong><?= (int) (($s['transfers']['in_transit'] ?? 0) + ($s['transfers']['requested'] ?? 0)) ?></strong></li>
            </ul>
            <p class="od-foot"><a href="<?= asset('purchase-entry.php') ?>">Purchase entry →</a> · <a href="<?= asset('transfers.php') ?>">Transfers →</a></p>
        </div>
    </div>

    <div class="od-grid-2">
        <div class="od-card">
            <h3 class="od-card-title">Warehouse stock</h3>
            <?php if (empty($s['inventory']['warehouses'])): ?>
                <p class="od-empty">Add warehouses under Outlets to see stock by location.</p>
            <?php else: ?>
                <table class="od-table">
                    <thead><tr><th>Location</th><th>SKUs</th><th>Units</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['inventory']['warehouses'] as $w): ?>
                        <tr>
                            <td><?= e((string) $w['name']) ?></td>
                            <td><?= (int) $w['sku_lines'] ?></td>
                            <td><?= number_format((int) $w['units']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p class="od-foot"><a href="<?= asset('inventory.php') ?>">Inventory →</a></p>
        </div>

        <div class="od-card">
            <h3 class="od-card-title">Open cash sessions</h3>
            <?php if (empty($s['registers']['sessions'])): ?>
                <p class="od-empty">No register session open right now.</p>
            <?php else: ?>
                <table class="od-table">
                    <thead><tr><th>Register</th><th>Store</th><th>Cashier</th><th>Opening</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['registers']['sessions'] as $sess): ?>
                        <tr>
                            <td><?= e((string) ($sess['register_name'] ?? '')) ?></td>
                            <td><?= e((string) ($sess['outlet_name'] ?? '—')) ?></td>
                            <td><?= e((string) ($sess['cashier_name'] ?? '')) ?></td>
                            <td><?= e($currency) ?><?= number_format((float) ($sess['opening_cash'] ?? 0), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="od-card od-card-muted">
        <h3 class="od-card-title">Channels &amp; integrations</h3>
        <p class="od-muted" style="margin:0 0 10px;font-size:13px;">Sales channels above come from each order&apos;s channel field. Configure messaging and online checkout in Integrations.</p>
        <a href="<?= asset('integrations-whatsapp.php') ?>" class="od-pill">WhatsApp</a>
        <a href="<?= asset('online-store.php') ?>" class="od-pill">Online store</a>
        <a href="<?= asset('orders.php') ?>" class="od-pill">All orders</a>
        <a href="<?= asset('reports.php?type=sales-summary') ?>" class="od-pill">Sales report</a>
    </div>
</section>

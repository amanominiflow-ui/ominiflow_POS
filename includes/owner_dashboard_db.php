<?php
/**
 * Owner / control-panel aggregates for Home dashboard (read-only; does not alter core flows).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function owner_dashboard_can_view(?array $user = null): bool
{
    return user_has_full_access($user);
}

function owner_dashboard_report_days(): int
{
    $days = 30;
    if (defined('OWNER_DASHBOARD_REPORT_DAYS')) {
        $days = (int) OWNER_DASHBOARD_REPORT_DAYS;
    }
    return max(7, min(90, $days));
}

function owner_dashboard_format_channel_label(string $channel): string
{
    $channel = strtolower(trim($channel));
    if ($channel === '') {
        $channel = 'pos';
    }
    return ucwords(str_replace(['_', '-'], ' ', $channel));
}

/**
 * @return array{business_name: string, report_days: int, currency_symbol: string}
 */
function owner_dashboard_meta(?int $businessId = null): array
{
    $bid = $businessId ?: current_business_id();
    $name = defined('APP_NAME') ? (string) APP_NAME : 'POS';
    $currency = '₹';
    try {
        require_once __DIR__ . '/orders_db.php';
        $store = get_store_settings($bid);
        if (!empty($store['store_name'])) {
            $name = (string) $store['store_name'];
        }
        if (!empty($store['currency_symbol'])) {
            $currency = (string) $store['currency_symbol'];
        }
    } catch (Throwable $e) {
        // keep defaults
    }
    return [
        'business_name' => $name,
        'report_days' => owner_dashboard_report_days(),
        'currency_symbol' => $currency,
    ];
}

/**
 * @return array<string, mixed>
 */
function owner_dashboard_empty_snapshot(?int $businessId = null): array
{
    $meta = owner_dashboard_meta($businessId);
    return [
        'meta' => $meta,
        'sales' => [
            'today' => ['revenue' => 0.0, 'orders' => 0],
            'week' => ['revenue' => 0.0, 'orders' => 0],
            'month' => ['revenue' => 0.0, 'orders' => 0],
        ],
        'channels' => [],
        'outlets' => [],
        'fulfillment' => [],
        'payments' => [],
        'registers' => ['open_sessions' => 0, 'mismatch_today' => 0, 'sessions' => []],
        'inventory' => ['low_stock' => 0, 'out_of_stock' => 0, 'warehouses' => []],
        'purchase' => ['pending_cost' => 0, 'barcode_pending' => 0, 'finalized_month' => 0],
        'transfers' => ['in_transit' => 0, 'requested' => 0],
        'alerts' => [
            'low_stock' => 0,
            'out_of_stock' => 0,
            'cash_mismatch_today' => 0,
            'open_drawers' => 0,
            'pending_fulfillment' => 0,
            'pending_purchase_cost' => 0,
            'pending_payments' => 0,
        ],
    ];
}

function owner_dashboard_table_exists(PDO $db, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $db->prepare('
            SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1
        ');
        $stmt->execute(['t' => $table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function owner_dashboard_column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $db->prepare('
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1
        ');
        $stmt->execute(['t' => $table, 'c' => $column]);
        $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * @return array<string, mixed>
 */
function owner_dashboard_snapshot(?int $businessId = null): array
{
    $bid = $businessId ?: current_business_id();
    $db = get_db();
    $days = owner_dashboard_report_days();

    $sales = owner_dashboard_sales_periods($db, $bid);
    $channels = owner_dashboard_sales_by_channel($db, $bid, $days);
    $outlets = owner_dashboard_sales_by_outlet($db, $bid, 8);
    $fulfillment = owner_dashboard_fulfillment_counts($db, $bid);
    $payments = owner_dashboard_payment_mix($db, $bid, $days);
    $registers = owner_dashboard_register_summary($db, $bid);
    $inventory = owner_dashboard_inventory_summary($db, $bid);
    $purchase = owner_dashboard_purchase_summary($db, $bid);
    $transfers = owner_dashboard_transfer_summary($db, $bid);
    $alerts = owner_dashboard_alert_counts($db, $bid, $inventory, $registers, $fulfillment, $purchase);

    return [
        'meta' => owner_dashboard_meta($bid),
        'sales' => $sales,
        'channels' => $channels,
        'outlets' => $outlets,
        'fulfillment' => $fulfillment,
        'payments' => $payments,
        'registers' => $registers,
        'inventory' => $inventory,
        'purchase' => $purchase,
        'transfers' => $transfers,
        'alerts' => $alerts,
    ];
}

/**
 * @return array{today: array{revenue: float, orders: int}, week: array{revenue: float, orders: int}, month: array{revenue: float, orders: int}}
 */
function owner_dashboard_sales_periods(PDO $db, int $bid): array
{
    $base = '
        SELECT COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS orders
        FROM orders
        WHERE business_id = :bid AND order_status = "completed"
    ';
    $out = [
        'today' => ['revenue' => 0.0, 'orders' => 0],
        'week' => ['revenue' => 0.0, 'orders' => 0],
        'month' => ['revenue' => 0.0, 'orders' => 0],
    ];
    try {
        $stmt = $db->prepare($base . ' AND DATE(created_at) = CURDATE()');
        $stmt->execute(['bid' => $bid]);
        $row = $stmt->fetch() ?: [];
        $out['today'] = ['revenue' => (float) ($row['revenue'] ?? 0), 'orders' => (int) ($row['orders'] ?? 0)];

        $stmt = $db->prepare($base . ' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)');
        $stmt->execute(['bid' => $bid]);
        $row = $stmt->fetch() ?: [];
        $out['week'] = ['revenue' => (float) ($row['revenue'] ?? 0), 'orders' => (int) ($row['orders'] ?? 0)];

        $stmt = $db->prepare($base . ' AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")');
        $stmt->execute(['bid' => $bid]);
        $row = $stmt->fetch() ?: [];
        $out['month'] = ['revenue' => (float) ($row['revenue'] ?? 0), 'orders' => (int) ($row['orders'] ?? 0)];
    } catch (Throwable $e) {
        // keep zeros
    }
    return $out;
}

/**
 * @return array<int, array{channel: string, label: string, revenue: float, orders: int}>
 */
function owner_dashboard_sales_by_channel(PDO $db, int $bid, int $days = 30): array
{
    $daysSql = max(0, $days - 1);
    try {
        if (owner_dashboard_column_exists($db, 'orders', 'sales_channel')) {
            $stmt = $db->prepare('
                SELECT COALESCE(sales_channel, "pos") AS channel,
                       COALESCE(SUM(total_amount), 0) AS revenue,
                       COUNT(*) AS orders
                FROM orders
                WHERE business_id = :bid AND order_status = "completed"
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL ' . $daysSql . ' DAY)
                GROUP BY COALESCE(sales_channel, "pos")
                ORDER BY revenue DESC
            ');
            $stmt->execute(['bid' => $bid]);
            $rows = $stmt->fetchAll() ?: [];
        } else {
            $stmt = $db->prepare('
                SELECT "pos" AS channel,
                       COALESCE(SUM(total_amount), 0) AS revenue,
                       COUNT(*) AS orders
                FROM orders
                WHERE business_id = :bid AND order_status = "completed"
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL ' . $daysSql . ' DAY)
            ');
            $stmt->execute(['bid' => $bid]);
            $rows = $stmt->fetchAll() ?: [];
        }
        $out = [];
        foreach ($rows as $r) {
            $ch = (string) ($r['channel'] ?? 'pos');
            $out[] = [
                'channel' => $ch,
                'label' => owner_dashboard_format_channel_label($ch),
                'revenue' => (float) ($r['revenue'] ?? 0),
                'orders' => (int) ($r['orders'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<int, array{outlet_id: int, outlet_name: string, revenue: float, orders: int}>
 */
function owner_dashboard_sales_by_outlet(PDO $db, int $bid, int $limit = 8): array
{
    if (!owner_dashboard_column_exists($db, 'orders', 'outlet_id')) {
        return [];
    }
    try {
        $stmt = $db->prepare('
            SELECT COALESCE(o.outlet_id, 0) AS outlet_id,
                   COALESCE(ot.name, "Unassigned") AS outlet_name,
                   COALESCE(SUM(o.total_amount), 0) AS revenue,
                   COUNT(*) AS orders
            FROM orders o
            LEFT JOIN outlets ot ON ot.id = o.outlet_id AND ot.business_id = :bid_ot
            WHERE o.business_id = :bid AND o.order_status = "completed"
              AND o.created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")
            GROUP BY COALESCE(o.outlet_id, 0), COALESCE(ot.name, "Unassigned")
            ORDER BY revenue DESC
            LIMIT ' . (int) max(1, min(20, $limit)) . '
        ');
        $stmt->execute(['bid' => $bid, 'bid_ot' => $bid]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $out[] = [
                'outlet_id' => (int) ($r['outlet_id'] ?? 0),
                'outlet_name' => (string) ($r['outlet_name'] ?? 'Unassigned'),
                'revenue' => (float) ($r['revenue'] ?? 0),
                'orders' => (int) ($r['orders'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<string, int>
 */
function owner_dashboard_fulfillment_counts(PDO $db, int $bid): array
{
    if (!owner_dashboard_column_exists($db, 'orders', 'fulfillment_status')) {
        return [];
    }
    $keys = ['pending', 'confirmed', 'packed', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled', 'returned'];
    $out = array_fill_keys($keys, 0);
    try {
        $stmt = $db->prepare('
            SELECT fulfillment_status AS st, COUNT(*) AS cnt
            FROM orders
            WHERE business_id = :bid
              AND order_status NOT IN ("cancelled", "hold")
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY fulfillment_status
        ');
        $stmt->execute(['bid' => $bid]);
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $st = (string) ($r['st'] ?? '');
            if (isset($out[$st])) {
                $out[$st] = (int) ($r['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * @return array<int, array{method: string, label: string, revenue: float, orders: int}>
 */
function owner_dashboard_payment_label_map(PDO $db, int $bid): array
{
    $map = [];
    if (!owner_dashboard_table_exists($db, 'payment_options')) {
        return $map;
    }
    try {
        $hasBiz = owner_dashboard_column_exists($db, 'payment_options', 'business_id');
        if ($hasBiz) {
            $stmt = $db->prepare('
                SELECT LOWER(payment_mode) AS mode, display_name
                FROM payment_options
                WHERE business_id = :bid AND status = "active"
            ');
            $stmt->execute(['bid' => $bid]);
        } else {
            $stmt = $db->query('
                SELECT LOWER(payment_mode) AS mode, display_name
                FROM payment_options WHERE status = "active"
            ');
        }
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $mode = strtolower(trim((string) ($row['mode'] ?? '')));
            if ($mode === '') {
                continue;
            }
            $map[$mode] = (string) ($row['display_name'] ?? owner_dashboard_format_channel_label($mode));
        }
    } catch (Throwable $e) {
    }
    return $map;
}

function owner_dashboard_payment_mix(PDO $db, int $bid, int $days = 30): array
{
    $daysSql = max(0, $days - 1);
    $labelMap = owner_dashboard_payment_label_map($db, $bid);
    try {
        $stmt = $db->prepare('
            SELECT LOWER(COALESCE(payment_method, "cash")) AS method,
                   COALESCE(SUM(total_amount), 0) AS revenue,
                   COUNT(*) AS orders
            FROM orders
            WHERE business_id = :bid AND order_status = "completed"
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL ' . $daysSql . ' DAY)
            GROUP BY LOWER(COALESCE(payment_method, "cash"))
            ORDER BY revenue DESC
        ');
        $stmt->execute(['bid' => $bid]);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $m = (string) ($r['method'] ?? 'cash');
            $out[] = [
                'method' => $m,
                'label' => $labelMap[$m] ?? owner_dashboard_format_channel_label($m),
                'revenue' => (float) ($r['revenue'] ?? 0),
                'orders' => (int) ($r['orders'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{open_sessions: int, mismatch_today: int, sessions: array<int, array<string, mixed>>}
 */
function owner_dashboard_register_summary(PDO $db, int $bid): array
{
    if (!owner_dashboard_table_exists($db, 'register_sessions')) {
        return ['open_sessions' => 0, 'mismatch_today' => 0, 'sessions' => []];
    }
    $hasBiz = owner_dashboard_column_exists($db, 'register_sessions', 'business_id');
    $out = ['open_sessions' => 0, 'mismatch_today' => 0, 'sessions' => []];
    try {
        if ($hasBiz) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM register_sessions WHERE business_id = :bid AND status = "open"');
            $stmt->execute(['bid' => $bid]);
            $out['open_sessions'] = (int) $stmt->fetchColumn();

            if (owner_dashboard_column_exists($db, 'register_sessions', 'closed_at')) {
                $stmt = $db->prepare('
                    SELECT COUNT(*) FROM register_sessions
                    WHERE business_id = :bid AND status = "closed"
                      AND DATE(closed_at) = CURDATE()
                      AND ABS(cash_difference) > 0.01
                ');
                $stmt->execute(['bid' => $bid]);
                $out['mismatch_today'] = (int) $stmt->fetchColumn();
            }

            $regBiz = owner_dashboard_column_exists($db, 'registers', 'business_id')
                ? ' AND r.business_id = :bid_r' : '';
            $stmt = $db->prepare('
                SELECT s.id, s.status, s.opening_cash, s.closing_cash_expected, s.closing_cash_actual,
                       s.cash_difference, r.name AS register_name, o.name AS outlet_name,
                       COALESCE(u.name, "Cashier") AS cashier_name
                FROM register_sessions s
                JOIN registers r ON r.id = s.register_id' . $regBiz . '
                LEFT JOIN outlets o ON o.id = r.outlet_id AND o.business_id = :bid_o
                LEFT JOIN users u ON u.id = s.user_id
                WHERE s.business_id = :bid AND s.status = "open"
                ORDER BY s.id DESC
                LIMIT 6
            ');
            $params = ['bid' => $bid, 'bid_o' => $bid];
            if ($regBiz !== '') {
                $params['bid_r'] = $bid;
            }
            $stmt->execute($params);
            $out['sessions'] = $stmt->fetchAll() ?: [];
        } else {
            $stmt = $db->query('SELECT COUNT(*) FROM register_sessions WHERE status = "open"');
            $out['open_sessions'] = (int) $stmt->fetchColumn();
            $stmt = $db->query('
                SELECT s.id, s.status, s.opening_cash, s.closing_cash_expected, s.closing_cash_actual,
                       s.cash_difference, r.name AS register_name, NULL AS outlet_name,
                       COALESCE(u.name, "Cashier") AS cashier_name
                FROM register_sessions s
                JOIN registers r ON r.id = s.register_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE s.status = "open"
                ORDER BY s.id DESC
                LIMIT 6
            ');
            $out['sessions'] = $stmt->fetchAll() ?: [];
        }
    } catch (Throwable $e) {
        // empty
    }
    return $out;
}

/**
 * @return array{low_stock: int, out_of_stock: int, warehouses: array<int, array{name: string, sku_lines: int, units: int}>}
 */
function owner_dashboard_inventory_summary(PDO $db, int $bid): array
{
    $summary = ['low_stock' => 0, 'out_of_stock' => 0, 'warehouses' => []];
    try {
        $stmt = $db->prepare('
            SELECT
                SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock,
                SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock
            FROM products WHERE business_id = :bid
        ');
        $stmt->execute(['bid' => $bid]);
        $row = $stmt->fetch() ?: [];
        $summary['low_stock'] = (int) ($row['low_stock'] ?? 0);
        $summary['out_of_stock'] = (int) ($row['out_of_stock'] ?? 0);
    } catch (Throwable $e) {
    }

    if (!owner_dashboard_table_exists($db, 'warehouses') || !owner_dashboard_table_exists($db, 'warehouse_stock')) {
        return $summary;
    }
    try {
        $stmt = $db->prepare('
            SELECT w.name,
                   COUNT(DISTINCT ws.product_id) AS sku_lines,
                   COALESCE(SUM(ws.stock_quantity), 0) AS units
            FROM warehouses w
            LEFT JOIN warehouse_stock ws ON ws.warehouse_id = w.id
            WHERE w.business_id = :bid AND w.status = "active"
            GROUP BY w.id, w.name
            ORDER BY w.name ASC
            LIMIT 12
        ');
        $stmt->execute(['bid' => $bid]);
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $summary['warehouses'][] = [
                'name' => (string) ($r['name'] ?? 'Warehouse'),
                'sku_lines' => (int) ($r['sku_lines'] ?? 0),
                'units' => (int) ($r['units'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
    }
    return $summary;
}

/**
 * @return array<string, int>
 */
function owner_dashboard_purchase_summary(PDO $db, int $bid): array
{
    $empty = [
        'pending_cost' => 0,
        'barcode_pending' => 0,
        'finalized_month' => 0,
    ];
    if (!owner_dashboard_table_exists($db, 'purchase_entries')) {
        return $empty;
    }
    try {
        $stmt = $db->prepare('
            SELECT
                SUM(CASE WHEN status IN ("draft","pending_cost") THEN 1 ELSE 0 END) AS pending_cost,
                SUM(CASE WHEN status = "finalized" THEN 1 ELSE 0 END) AS barcode_pending,
                SUM(CASE WHEN status IN ("finalized","barcode_generated","completed")
                    AND created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN 1 ELSE 0 END) AS finalized_month
            FROM purchase_entries WHERE business_id = :bid
        ');
        $stmt->execute(['bid' => $bid]);
        $row = $stmt->fetch() ?: [];
        $empty['pending_cost'] = (int) ($row['pending_cost'] ?? 0);
        $empty['barcode_pending'] = (int) ($row['barcode_pending'] ?? 0);
        $empty['finalized_month'] = (int) ($row['finalized_month'] ?? 0);
    } catch (Throwable $e) {
    }
    return $empty;
}

/**
 * @return array{in_transit: int, requested: int}
 */
function owner_dashboard_transfer_summary(PDO $db, int $bid): array
{
    $out = ['in_transit' => 0, 'requested' => 0];
    if (!owner_dashboard_table_exists($db, 'stock_transfers')) {
        return $out;
    }
    try {
        if (owner_dashboard_column_exists($db, 'warehouses', 'business_id')) {
            $stmt = $db->prepare('
                SELECT st.status, COUNT(*) AS cnt
                FROM stock_transfers st
                INNER JOIN warehouses w ON w.id = st.source_warehouse_id AND w.business_id = :bid
                WHERE st.status IN ("requested","in_transit","draft")
                GROUP BY st.status
            ');
            $stmt->execute(['bid' => $bid]);
        } else {
            $stmt = $db->query('
                SELECT status, COUNT(*) AS cnt FROM stock_transfers
                WHERE status IN ("requested","in_transit","draft") GROUP BY status
            ');
        }
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $st = (string) ($r['status'] ?? '');
            $cnt = (int) ($r['cnt'] ?? 0);
            if ($st === 'in_transit') {
                $out['in_transit'] = $cnt;
            } elseif ($st === 'requested' || $st === 'draft') {
                $out['requested'] += $cnt;
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * @param array{low_stock: int, out_of_stock: int} $inventory
 * @return array<string, int>
 */
function owner_dashboard_alert_counts(
    PDO $db,
    int $bid,
    array $inventory,
    array $registers = [],
    array $fulfillment = [],
    array $purchase = []
): array {
    $alerts = [
        'low_stock' => (int) ($inventory['low_stock'] ?? 0),
        'out_of_stock' => (int) ($inventory['out_of_stock'] ?? 0),
        'cash_mismatch_today' => (int) ($registers['mismatch_today'] ?? 0),
        'open_drawers' => (int) ($registers['open_sessions'] ?? 0),
        'pending_fulfillment' => (int) ($fulfillment['pending'] ?? 0)
            + (int) ($fulfillment['packed'] ?? 0)
            + (int) ($fulfillment['shipped'] ?? 0),
        'pending_purchase_cost' => (int) ($purchase['pending_cost'] ?? 0),
    ];

    try {
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM orders
            WHERE business_id = :bid AND payment_status = "pending"
              AND order_status NOT IN ("cancelled")
        ');
        $stmt->execute(['bid' => $bid]);
        $alerts['pending_payments'] = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $alerts['pending_payments'] = 0;
    }

    return $alerts;
}

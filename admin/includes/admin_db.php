<?php
/**
 * Super Admin Database Operations & Metrics for OminiFlow POS
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/features.php';

/**
 * Fetch high-level system statistics for the Super Admin Dashboard
 */
function admin_get_stats(): array {
    ensure_features_schema();
    $db = get_db();
    $stats = [
        'total_clients' => 0,
        'active_subscriptions' => 0,
        'suspended_subscriptions' => 0,
        'total_users' => 0,
        'total_orders' => 0,
        'total_revenue' => 0.0,
        'total_outlets' => 0,
        'total_products' => 0,
    ];

    try {
        $stats['total_clients'] = (int) $db->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
        $stats['active_subscriptions'] = (int) $db->query("SELECT COUNT(*) FROM businesses WHERE subscription_status = 'active'")->fetchColumn();
        $stats['suspended_subscriptions'] = (int) $db->query("SELECT COUNT(*) FROM businesses WHERE subscription_status IN ('suspended', 'inactive')")->fetchColumn();
        $stats['total_users'] = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        
        $orderRow = $db->query('SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev FROM orders')->fetch(PDO::FETCH_ASSOC);
        if ($orderRow) {
            $stats['total_orders'] = (int)$orderRow['cnt'];
            $stats['total_revenue'] = (float)$orderRow['rev'];
        }

        $stats['total_outlets'] = (int) $db->query('SELECT COUNT(*) FROM outlets')->fetchColumn();
        $stats['total_products'] = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    } catch (PDOException $e) {}

    return $stats;
}

/**
 * Fetch clients with their primary admin user, active feature count, outlets count, orders count
 */
function admin_get_clients(string $search = '', string $status = '', string $plan = '', int $limit = 50, int $offset = 0): array {
    ensure_features_schema();
    $db = get_db();

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(b.name LIKE :s1 OR b.email LIKE :s2 OR b.phone LIKE :s3 OR u.name LIKE :s4 OR u.email LIKE :s5 OR b.organization_id LIKE :s6)';
        $like = '%' . $search . '%';
        $params['s1'] = $like;
        $params['s2'] = $like;
        $params['s3'] = $like;
        $params['s4'] = $like;
        $params['s5'] = $like;
        $params['s6'] = $like;
    }

    if ($status !== '') {
        $where[] = 'b.subscription_status = :status';
        $params['status'] = $status;
    }

    if ($plan !== '') {
        $where[] = 'b.subscription_plan = :plan';
        $params['plan'] = $plan;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT 
            b.*,
            u.id AS primary_user_id,
            u.name AS owner_name,
            u.email AS owner_email,
            u.phone AS owner_phone,
            (SELECT COUNT(*) FROM users WHERE business_id = b.id) AS user_count,
            (SELECT COUNT(*) FROM outlets WHERE business_id = b.id) AS outlet_count,
            (SELECT COUNT(*) FROM orders WHERE business_id = b.id) AS order_count,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE business_id = b.id) AS total_revenue
        FROM businesses b
        LEFT JOIN users u ON u.business_id = b.id AND (u.role = 'admin' OR u.id = (SELECT MIN(id) FROM users WHERE business_id = b.id))
        {$whereSql}
        GROUP BY b.id
        ORDER BY b.id DESC
        LIMIT :limit OFFSET :offset
    ";

    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Attach feature count for each client
        $totalSystemFeatures = (int)$db->query('SELECT COUNT(*) FROM system_features')->fetchColumn();
        foreach ($clients as &$client) {
            $bid = (int)$client['id'];
            $activeFeats = 0;
            $featRows = get_business_features_map($bid);
            foreach ($featRows as $fr) {
                if (!empty($fr['is_enabled'])) {
                    $activeFeats++;
                }
            }
            $client['enabled_features_count'] = $activeFeats;
            $client['total_features_count'] = count($featRows) ?: $totalSystemFeatures;
        }
        unset($client);

        return $clients;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Count total clients matching filters (for pagination)
 */
function admin_count_clients(string $search = '', string $status = '', string $plan = ''): int {
    ensure_features_schema();
    $db = get_db();
    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(b.name LIKE :s1 OR b.email LIKE :s2 OR b.phone LIKE :s3 OR u.name LIKE :s4 OR u.email LIKE :s5)';
        $like = '%' . $search . '%';
        $params['s1'] = $like;
        $params['s2'] = $like;
        $params['s3'] = $like;
        $params['s4'] = $like;
        $params['s5'] = $like;
    }

    if ($status !== '') {
        $where[] = 'b.subscription_status = :status';
        $params['status'] = $status;
    }

    if ($plan !== '') {
        $where[] = 'b.subscription_plan = :plan';
        $params['plan'] = $plan;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
        SELECT COUNT(DISTINCT b.id)
        FROM businesses b
        LEFT JOIN users u ON u.business_id = b.id
        {$whereSql}
    ";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Fetch a single client business record with full details
 */
function admin_get_client_by_id(int $businessId): ?array {
    ensure_features_schema();
    $db = get_db();
    try {
        $stmt = $db->prepare('
            SELECT b.*,
                   u.id AS primary_user_id,
                   u.name AS owner_name,
                   u.email AS owner_email,
                   u.phone AS owner_phone
            FROM businesses b
            LEFT JOIN users u ON u.business_id = b.id AND (u.role = ' . "'admin'" . ' OR u.id = (SELECT MIN(id) FROM users WHERE business_id = b.id))
            WHERE b.id = :bid
            LIMIT 1
        ');
        $stmt->execute(['bid' => $businessId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        return $client ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Search clients and users by email or name for live search / 1-click login
 */
function admin_search_clients_quick(string $query): array {
    ensure_features_schema();
    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $db = get_db();
    try {
        $like = '%' . $q . '%';
        $stmt = $db->prepare('
            SELECT 
                u.id AS user_id,
                u.name AS user_name,
                u.email AS user_email,
                u.phone AS user_phone,
                u.role AS user_role,
                b.id AS business_id,
                b.name AS business_name,
                b.subscription_status,
                b.subscription_plan,
                b.organization_id
            FROM users u
            JOIN businesses b ON b.id = u.business_id
            WHERE u.email LIKE ? 
               OR u.name LIKE ? 
               OR u.phone LIKE ? 
               OR b.name LIKE ? 
               OR b.organization_id LIKE ?
            ORDER BY u.id DESC
            LIMIT 10
        ');
        $stmt->execute([$like, $like, $like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

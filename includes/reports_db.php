<?php
/**
 * Real-Data Reporting Engine for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_sales_summary_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE o.order_status = "completed" AND o.business_id = :bid';
    $params = ['bid' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(o.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(o.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            COUNT(*) AS total_orders,
            COALESCE(SUM(o.subtotal), 0) AS gross_sales,
            COALESCE(SUM(o.discount_amount), 0) AS total_discounts,
            COALESCE(SUM(o.tax_amount), 0) AS total_tax,
            COALESCE(SUM(o.total_amount), 0) AS net_revenue
        FROM orders o
        {$where}
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch();

    // Payment methods breakdown
    $stmtPM = $db->prepare("
        SELECT 
            o.payment_method,
            COUNT(*) AS orders_count,
            COALESCE(SUM(o.total_amount), 0) AS amount
        FROM orders o
        {$where}
        GROUP BY o.payment_method
    ");
    $stmtPM->execute($params);
    $summary['by_payment_method'] = $stmtPM->fetchAll();

    return $summary;
}

function get_item_sales_report(string $dateFrom = '', string $dateTo = '', int $limit = 20, ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();

    $where = 'WHERE o.order_status = "completed" AND o.business_id = :bid';
    $params = ['bid' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(o.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(o.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            oi.product_id,
            oi.product_name,
            oi.product_sku,
            COALESCE(SUM(oi.quantity), 0) AS units_sold,
            COALESCE(SUM(oi.line_total), 0) AS total_revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        {$where}
        GROUP BY oi.product_id, oi.product_name, oi.product_sku
        ORDER BY units_sold DESC
        LIMIT " . max(1, $limit) . "
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_inventory_valuation_report(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT 
            COUNT(*) AS total_products,
            COALESCE(SUM(stock_quantity), 0) AS total_units_in_stock,
            COALESCE(SUM(cost_price * stock_quantity), 0) AS total_cost_value,
            COALESCE(SUM(selling_price * stock_quantity), 0) AS total_retail_value,
            SUM(CASE WHEN stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock_count,
            SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock_count
        FROM products
        WHERE business_id = :bid AND status = "active"
    ');
    $stmt->execute(['bid' => $bid]);
    return $stmt->fetch() ?: [];
}

function get_category_performance_report(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare('
        SELECT 
            COALESCE(c.name, "Uncategorized") AS category_name,
            COUNT(DISTINCT p.id) AS products_count,
            COALESCE(SUM(p.stock_quantity), 0) AS total_stock,
            COALESCE(SUM(oi.quantity), 0) AS total_units_sold,
            COALESCE(SUM(oi.line_total), 0) AS total_sales_value
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id AND p.business_id = :bid_p
        LEFT JOIN order_items oi ON oi.product_id = p.id
        WHERE c.business_id = :bid
        GROUP BY c.id, c.name
        ORDER BY total_sales_value DESC
    ');
    $stmt->execute(['bid' => $bid, 'bid_p' => $bid]);
    return $stmt->fetchAll();
}

function get_gst_tax_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE i.invoice_status != "cancelled" AND i.business_id = :bid';
    $params = ['bid' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(i.invoice_date) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(i.invoice_date) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            COUNT(*) AS total_invoices,
            COALESCE(SUM(i.taxable_amount), 0) AS total_taxable,
            COALESCE(SUM(i.cgst_amount), 0) AS total_cgst,
            COALESCE(SUM(i.sgst_amount), 0) AS total_sgst,
            COALESCE(SUM(i.igst_amount), 0) AS total_igst,
            COALESCE(SUM(i.tax_amount), 0) AS total_tax_collected,
            COALESCE(SUM(i.total_amount), 0) AS total_gross_invoiced
        FROM invoices i
        {$where}
    ");
    $stmt->execute($params);
    return $stmt->fetch() ?: [
        'total_invoices' => 0, 'total_taxable' => 0, 'total_cgst' => 0,
        'total_sgst' => 0, 'total_igst' => 0, 'total_tax_collected' => 0, 'total_gross_invoiced' => 0
    ];
}

function get_outlet_sales_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE o.order_status = "completed" AND o.business_id = :bid';
    $params = ['bid' => $bid, 'bid_ot' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(o.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(o.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            COALESCE(ot.name, 'Main Outlet') AS outlet_name,
            COALESCE(ot.code, 'OUT-MAIN') AS outlet_code,
            COUNT(o.id) AS total_orders,
            COALESCE(SUM(o.total_amount), 0) AS total_sales
        FROM orders o
        LEFT JOIN outlets ot ON ot.id = o.outlet_id AND ot.business_id = :bid_ot
        {$where}
        GROUP BY ot.id, ot.name, ot.code
        ORDER BY total_sales DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_sales_by_cashier_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE o.order_status = "completed" AND o.business_id = :bid';
    $params = ['bid' => $bid, 'bid_u' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(o.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(o.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            COALESCE(u.name, 'Primary Cashier') AS cashier_name,
            COALESCE(u.email, 'staff@ominiflow.pos') AS cashier_email,
            COUNT(o.id) AS total_bills,
            COALESCE(SUM(o.total_amount), 0) AS total_sales_collected,
            COALESCE(SUM(o.discount_amount), 0) AS total_discounts
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id AND u.business_id = :bid_u
        {$where}
        GROUP BY o.user_id, u.name, u.email
        ORDER BY total_sales_collected DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_payments_received_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE o.order_status = "completed" AND o.business_id = :bid';
    $params = ['bid' => $bid, 'bid_c' => $bid, 'bid_u' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(o.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(o.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            o.id AS order_id,
            o.order_number,
            o.created_at AS payment_date,
            COALESCE(c.name, 'Walk-in Customer') AS customer_name,
            o.payment_method,
            o.total_amount AS amount_received,
            COALESCE(u.name, 'Cashier') AS received_by
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id AND c.business_id = :bid_c
        LEFT JOIN users u ON u.id = o.user_id AND u.business_id = :bid_u
        {$where}
        ORDER BY o.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_credit_notes_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE cn.business_id = :bid';
    $params = ['bid' => $bid, 'bid_c' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(cn.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(cn.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            cn.*,
            COALESCE(c.name, 'Walk-in Customer') AS customer_name
        FROM credit_notes cn
        LEFT JOIN customers c ON c.id = cn.customer_id AND c.business_id = :bid_c
        {$where}
        ORDER BY cn.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_refunds_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE r.business_id = :bid';
    $params = ['bid' => $bid, 'bid_c' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(r.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(r.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            r.*,
            COALESCE(c.name, 'Walk-in Customer') AS customer_name
        FROM returns r
        LEFT JOIN customers c ON c.id = r.customer_id AND c.business_id = :bid_c
        {$where}
        ORDER BY r.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_customer_balances_report(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare("
        SELECT 
            c.id, c.name, c.phone, c.email,
            c.loyalty_points_balance,
            c.credit_limit,
            c.outstanding_receivable,
            COUNT(o.id) AS total_orders_placed,
            COALESCE(SUM(o.total_amount), 0) AS lifetime_spend
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id AND o.order_status = 'completed' AND o.business_id = :bid_o
        WHERE c.business_id = :bid
        GROUP BY c.id, c.name, c.phone, c.email, c.loyalty_points_balance, c.credit_limit, c.outstanding_receivable
        ORDER BY lifetime_spend DESC
    ");
    $stmt->execute(['bid' => $bid, 'bid_o' => $bid]);
    return $stmt->fetchAll();
}

function get_vendor_balances_report(?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $stmt = $db->prepare("
        SELECT 
            v.*,
            COUNT(po.id) AS total_pos_created,
            COALESCE(SUM(po.total_amount), 0) AS total_procured_value
        FROM vendors v
        LEFT JOIN purchase_orders po ON po.vendor_id = v.id AND po.business_id = :bid_po
        WHERE v.business_id = :bid
        GROUP BY v.id
        ORDER BY v.outstanding_balance DESC
    ");
    $stmt->execute(['bid' => $bid, 'bid_po' => $bid]);
    return $stmt->fetchAll();
}

function get_purchases_summary_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE po.business_id = :bid';
    $params = ['bid' => $bid, 'bid_v' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(po.created_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(po.created_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            po.*,
            v.name AS vendor_name,
            v.company_name AS vendor_company
        FROM purchase_orders po
        LEFT JOIN vendors v ON v.id = po.vendor_id AND v.business_id = :bid_v
        {$where}
        ORDER BY po.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_register_shifts_report(string $dateFrom = '', string $dateTo = '', ?int $businessId = null): array {
    $db = get_db();
    $bid = $businessId ?: current_business_id();
    $where = 'WHERE rs.business_id = :bid';
    $params = ['bid' => $bid, 'bid_r' => $bid, 'bid_u' => $bid];
    if ($dateFrom !== '') {
        $where .= ' AND DATE(rs.opened_at) >= :d_from';
        $params['d_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(rs.opened_at) <= :d_to';
        $params['d_to'] = $dateTo;
    }

    $stmt = $db->prepare("
        SELECT 
            rs.*,
            r.name AS register_name,
            r.code AS register_code,
            COALESCE(u.name, 'Staff') AS cashier_name
        FROM register_sessions rs
        LEFT JOIN registers r ON r.id = rs.register_id AND r.business_id = :bid_r
        LEFT JOIN users u ON u.id = rs.user_id AND u.business_id = :bid_u
        {$where}
        ORDER BY rs.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

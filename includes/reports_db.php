<?php
/**
 * Real-Data Reporting Engine for OminiFlow POS (Zoho POS Parity)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function get_sales_summary_report(string $dateFrom = '', string $dateTo = ''): array {
    $db = get_db();

    $where = 'WHERE o.order_status = "completed"';
    $params = [];
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

function get_item_sales_report(string $dateFrom = '', string $dateTo = '', int $limit = 20): array {
    $db = get_db();

    $where = 'WHERE o.order_status = "completed"';
    $params = [];
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

function get_inventory_valuation_report(): array {
    $db = get_db();
    $stmt = $db->query('
        SELECT 
            COUNT(*) AS total_products,
            COALESCE(SUM(stock_quantity), 0) AS total_units_in_stock,
            COALESCE(SUM(cost_price * stock_quantity), 0) AS total_cost_value,
            COALESCE(SUM(selling_price * stock_quantity), 0) AS total_retail_value,
            SUM(CASE WHEN stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock_count,
            SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock_count
        FROM products
        WHERE status = "active"
    ');
    return $stmt->fetch();
}

function get_category_performance_report(): array {
    $db = get_db();
    return $db->query('
        SELECT 
            COALESCE(c.name, "Uncategorized") AS category_name,
            COUNT(DISTINCT p.id) AS products_count,
            COALESCE(SUM(p.stock_quantity), 0) AS total_stock,
            COALESCE(SUM(oi.quantity), 0) AS total_units_sold,
            COALESCE(SUM(oi.line_total), 0) AS total_sales_value
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id
        LEFT JOIN order_items oi ON oi.product_id = p.id
        GROUP BY c.id, c.name
        ORDER BY total_sales_value DESC
    ')->fetchAll();
}

function get_gst_tax_report(string $dateFrom = '', string $dateTo = ''): array {
    $db = get_db();
    $where = 'WHERE i.invoice_status != "cancelled"';
    $params = [];
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

function get_outlet_sales_report(string $dateFrom = '', string $dateTo = ''): array {
    $db = get_db();
    $where = 'WHERE o.order_status = "completed"';
    $params = [];
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
        LEFT JOIN outlets ot ON ot.id = o.outlet_id
        {$where}
        GROUP BY ot.id, ot.name, ot.code
        ORDER BY total_sales DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

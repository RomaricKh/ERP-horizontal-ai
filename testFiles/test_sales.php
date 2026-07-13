<?php
$db = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$itemId = 9;
$businessId = 3;

$metricsStmt = $db->prepare("
            SELECT 
                COALESCE(SUM(sid.quantity), 0) AS total_sold,
                COALESCE(SUM(sid.total_price_fc), 0) AS total_revenue,
                MAX(si.invoice_date) AS last_sold_date,
                (SELECT COALESCE(SUM(sid2.quantity), 0) 
                 FROM si_details sid2 
                 JOIN sales_invoices si2 ON si2.id = sid2.sales_invoice_id 
                 WHERE sid2.item_id = :item1 
                   AND si2.business_id = :biz1 
                   AND si2.status IN ('DELIVERED', 'PAID')
                   AND si2.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ) AS units_sold_30_days
            FROM si_details sid
            JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            WHERE sid.item_id = :item2 
              AND si.business_id = :biz2 
              AND si.status IN ('DELIVERED', 'PAID')
        ");
        $metricsStmt->execute([
            'item1' => $itemId, 'biz1' => $businessId,
            'item2' => $itemId, 'biz2' => $businessId
        ]);
print_r($metricsStmt->fetch(PDO::FETCH_ASSOC));

<?php
declare(strict_types=1);

function admin_action_counts(PDO $pdo): array
{
    $counts = ['pending_payments' => 0, 'new_orders' => 0, 'low_stock' => 0, 'missing_images' => 0, 'missing_pricing' => 0, 'nutrition_review' => 0];
    $counts['pending_payments'] = (int) $pdo->query("SELECT COUNT(*) FROM yape_orders WHERE status='pendiente'")->fetchColumn();
    $counts['new_orders'] = (int) $pdo->query("SELECT COUNT(*) FROM yape_orders WHERE fulfillment_status IN ('pendiente','preparando')")->fetchColumn();
    $counts['low_stock'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE catalog_scope='master' AND stock<=min_stock")->fetchColumn();
    $counts['missing_images'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE catalog_scope='master' AND (image_url IS NULL OR image_url='')")->fetchColumn();
    $counts['missing_pricing'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE catalog_scope='master' AND (price<=0 OR cost_price<=0)")->fetchColumn();
    try {
        $counts['nutrition_review'] = (int) $pdo->query("SELECT COUNT(*) FROM products p LEFT JOIN product_nutrition n ON n.product_id=p.id WHERE p.catalog_scope='master' AND COALESCE(n.applicability,'review')='review'")->fetchColumn();
    } catch (PDOException) {
        $counts['nutrition_review'] = 0;
    }
    return $counts;
}

function admin_action_items(array $counts): array
{
    $map = [
        ['key' => 'pending_payments', 'label' => 'Pagos por revisar', 'priority' => 'urgent', 'href' => 'admin/pedidos_yape.php?status=pendiente'],
        ['key' => 'new_orders', 'label' => 'Pedidos por preparar', 'priority' => 'urgent', 'href' => 'admin/pedidos_yape.php'],
        ['key' => 'low_stock', 'label' => 'Productos por reponer', 'priority' => 'normal', 'href' => 'admin/todos_productos.php?status=out_of_stock'],
        ['key' => 'missing_images', 'label' => 'Productos sin imagen', 'priority' => 'normal', 'href' => 'admin/todos_productos.php'],
        ['key' => 'missing_pricing', 'label' => 'Productos sin precio/costo', 'priority' => 'normal', 'href' => 'admin/todos_productos.php?status=pending'],
        ['key' => 'nutrition_review', 'label' => 'Nutricion por revisar', 'priority' => 'normal', 'href' => 'admin/todos_productos.php'],
    ];
    $items = [];
    foreach ($map as $item) {
        $count = max(0, (int) ($counts[$item['key']] ?? 0));
        if ($count <= 0) continue;
        $item['count'] = $count;
        $items[] = $item;
    }
    return $items;
}

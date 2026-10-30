<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/productos.php');
}

verify_csrf();
$id = max(0, (int) ($_POST['id'] ?? 0));

if ($id <= 0) {
    flash('danger', 'No se recibió un producto válido.');
    redirect('admin/productos.php');
}

try {
    $statement = db()->prepare('DELETE FROM products WHERE id = :id');
    $statement->execute(['id' => $id]);

    if ($statement->rowCount() > 0) {
        flash('success', 'Producto eliminado del inventario.');
    } else {
        flash('warning', 'El producto ya no existe o fue eliminado anteriormente.');
    }
} catch (PDOException $exception) {
    flash('warning', 'El producto tiene movimientos o pertenece a un combo. Desactívalo en lugar de eliminarlo.');
}

redirect('admin/productos.php');

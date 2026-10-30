<?php

$page = file_get_contents(__DIR__ . '/../admin/index.php');
foreach (['Productos maestros', 'Productos publicados', 'Ganancia real', 'Ganancia potencial', 'Sin costo', 'Sin precio'] as $label) {
    if (!str_contains($page, $label)) {
        throw new RuntimeException($label);
    }
}

$header = file_get_contents(__DIR__ . '/../includes/admin_header.php');
foreach (['Productos', 'Herramientas avanzadas', 'Proveedores', 'Rentabilidad', 'Respaldo anterior'] as $label) {
    if (!str_contains($header, $label)) {
        throw new RuntimeException($label);
    }
}

echo "admin_dashboard_11604_test: OK\n";

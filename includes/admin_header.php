<?php

declare(strict_types=1);

require_admin();
header('Cache-Control: no-store, no-cache, must-revalidate, private');
$pageTitle = $pageTitle ?? 'Panel administrativo';
$activePage = $activePage ?? 'dashboard';
$flashMessage = pull_flash();
$adminRole = (string)($_SESSION['admin_role'] ?? 'admin');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($pageTitle) ?> | Devioz Store</title>
    <link rel="icon" href="<?= url('assets/img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= url('assets/css/styles.css?v=' . (string) @filemtime(__DIR__ . '/../assets/css/styles.css')) ?>">
    <link rel="stylesheet" href="<?= url('assets/css/admin_visual_v5.css?v=' . (string) @filemtime(__DIR__ . '/../assets/css/admin_visual_v5.css')) ?>">
</head>
<body class="admin-body">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a class="brand brand-light devioz-brand" href="<?= url('admin/index.php') ?>">
                <img class="devioz-logo devioz-logo-sidebar" src="<?= url('assets/img/logo-devioz-store.png') ?>" alt="">
                <span><strong>Devioz Store</strong><small>GESTIÓN DEL NEGOCIO</small></span>
            </a>
            <button class="icon-btn sidebar-close" id="sidebarClose" aria-label="Cerrar menú">×</button>
        </div>

        <nav class="sidebar-nav" aria-label="Navegación administrativa">
            <span class="nav-label">TRABAJO DIARIO</span>
            <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" href="<?= url('admin/index.php') ?>">
                <span class="nav-icon">⌂</span> Inicio
            </a>
            <a class="<?= in_array($activePage, ['all-products', 'products'], true) ? 'active' : '' ?>" href="<?= url('admin/todos_productos.php') ?>"><span class="nav-icon">▦</span> Productos</a>
            <a class="<?= $activePage === 'new' ? 'active' : '' ?>" href="<?= url('admin/producto_form.php') ?>">
                <span class="nav-icon">＋</span> Nuevo producto
            </a>
            <a class="<?= $activePage === 'categories' ? 'active' : '' ?>" href="<?= url('admin/categorias.php') ?>">
                <span class="nav-icon">☷</span> Categorías
            </a>
            <a class="<?= $activePage === 'combos' ? 'active' : '' ?>" href="<?= url('admin/combos.php') ?>">
                <span class="nav-icon">✦</span> Combos
            </a>
            <a class="<?= $activePage === 'movements' ? 'active' : '' ?>" href="<?= url('admin/movimientos.php') ?>">
                <span class="nav-icon">↕</span> Inventario
            </a>
            <span class="nav-label">VENTAS</span>
            <a class="<?= $activePage === 'payments' ? 'active' : '' ?>" href="<?= url('admin/pagos.php') ?>">
                <span class="nav-icon">Y</span> Pagos
            </a>
            <a class="<?= $activePage === 'yape-orders' ? 'active' : '' ?>" href="<?= url('admin/pedidos_yape.php') ?>">
                <span class="nav-icon">✓</span> Pedidos
            </a>
            <a class="<?= $activePage === 'incidents' ? 'active' : '' ?>" href="<?= url('admin/incidencias.php') ?>"><span class="nav-icon">!</span> Incidencias de entrega</a>
            <?php if($adminRole==='admin'): ?><a class="<?= $activePage === 'complaints' ? 'active' : '' ?>" href="<?= url('admin/quejas.php') ?>"><span class="nav-icon">✉</span> Quejas de clientes</a><?php endif; ?>
            <?php if($adminRole==='admin'): ?><a class="<?= $activePage === 'drivers' ? 'active' : '' ?>" href="<?= url('admin/motorizados.php') ?>"><span class="nav-icon">♧</span> Motorizados</a><?php endif; ?>
            <?php if(in_array($adminRole,['admin','atencion'],true)): ?><a class="<?= $activePage === 'customers' ? 'active' : '' ?>" href="<?= url('admin/clientes.php') ?>"><span class="nav-icon">♙</span> Clientes</a><?php endif ?>
            <?php if(in_array($adminRole,['admin','atencion'],true)): ?><a class="<?= $activePage === 'feedback' ? 'active' : '' ?>" href="<?= url('admin/satisfaccion.php') ?>"><span class="nav-icon">★</span> Satisfacción</a><?php endif ?>
            <span class="nav-label">TIENDA</span>
            <?php if($adminRole==='admin'): ?><a class="<?= $activePage === 'store-content' ? 'active' : '' ?>" href="<?= url('admin/contenido_tienda.php') ?>"><span class="nav-icon">▣</span> Contenido de tienda</a><?php endif ?>
            <a href="<?= url('admin/datos_empresa.php') ?>" class="<?= $activePage === 'receipt-business' ? 'active' : '' ?>"><span class="nav-icon">□</span> Datos de empresa</a>
            <a class="<?= $activePage === 'security' ? 'active' : '' ?>" href="<?= url('admin/seguridad.php') ?>">
                <span class="nav-icon">⌾</span> Seguridad
            </a>
            <a href="<?= url('index.php') ?>" target="_blank" rel="noopener">
                <span class="nav-icon">↗</span> Ver catálogo
            </a>
            <details class="sidebar-advanced" <?= in_array($activePage, ['pricing','shopping-sources','external-catalog','statistics','commercial','reservations','legacy-backup'], true) ? 'open' : '' ?>>
                <summary><span class="nav-icon">•••</span> Herramientas avanzadas</summary>
                <div>
                    <a class="<?= $activePage === 'pricing' ? 'active' : '' ?>" href="<?= url('admin/precios.php') ?>">Rentabilidad</a>
                    <a class="<?= $activePage === 'shopping-sources' ? 'active' : '' ?>" href="<?= url('admin/fuentes_compras.php') ?>">Proveedores</a>
                    <a class="<?= $activePage === 'external-catalog' ? 'active' : '' ?>" href="<?= url('admin/catalogo_externo.php') ?>">Catálogo externo</a>
                    <a href="<?= url('comparador.php') ?>" target="_blank" rel="noopener">Comparador</a>
                    <a href="<?= url('asistente_compras.php') ?>" target="_blank" rel="noopener">Asistente de IA</a>
                    <?php if($adminRole==='admin'): ?>
                        <a class="<?= $activePage === 'statistics' ? 'active' : '' ?>" href="<?= url('admin/estadisticas.php') ?>">Estadísticas</a>
                        <a class="<?= $activePage === 'commercial' ? 'active' : '' ?>" href="<?= url('admin/comercial.php') ?>">Delivery, cupones y roles</a>
                        <a class="<?= $activePage === 'reservations' ? 'active' : '' ?>" href="<?= url('admin/reservas.php') ?>">Reservas de stock</a>
                        <a class="<?= $activePage === 'legacy-backup' ? 'active' : '' ?>" href="<?= url('admin/respaldo_anterior.php') ?>">Respaldo anterior</a>
                        <a href="<?= url('admin/respaldo.php') ?>">Descargar respaldo</a>
                    <?php endif ?>
                </div>
            </details>
        </nav>

        <div class="sidebar-user">
            <span class="avatar"><?= e(strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1))) ?></span>
            <div>
                <strong><?= e($_SESSION['admin_name'] ?? 'Administrador') ?></strong>
                <small><?= e(ucfirst($adminRole)) ?></small>
            </div>
            <a class="logout-link" href="<?= url('logout.php') ?>" title="Cerrar sesión">↪</a>
        </div>
    </aside>

    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Cerrar menú" hidden></button>

    <div class="admin-shell">
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-btn menu-toggle" id="menuToggle" aria-label="Abrir menú">☰</button>
                <div>
                    <h1><?= e($pageTitle) ?></h1>
                    <p><?= e($pageSubtitle ?? 'Gestiona Devioz Store desde un solo lugar') ?></p>
                </div>
            </div>
            <div class="topbar-actions">
                <form class="admin-global-search" method="get" action="<?= url('admin/todos_productos.php') ?>" role="search">
                    <label for="adminGlobalSearch">Buscar en todos los productos</label>
                    <span aria-hidden="true">⌕</span>
                    <input id="adminGlobalSearch" type="search" name="q" maxlength="100" placeholder="Buscar producto, código, EAN o marca" autocomplete="off">
                    <kbd>/</kbd>
                </form>
                <a class="payment-alert-button" id="paymentAlertButton" href="<?= url('admin/pedidos_yape.php?status=pendiente') ?>" title="Ver pagos pendientes" aria-label="Ver pagos pendientes">
                    <span>Y</span><b id="paymentAlertBadge" hidden>0</b>
                </a>
                <span class="date-chip"><?= date('d/m/Y') ?></span>
                <a class="avatar" href="<?= url('admin/index.php') ?>"><?= e(strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1))) ?></a>
            </div>
        </header>

        <div class="admin-payment-toast" id="adminPaymentToast" role="status" aria-live="polite" hidden>
            <span class="admin-payment-toast-icon">Y</span>
            <div><small>NUEVO PAGO YAPE</small><strong id="adminPaymentToastTitle">Comprobante recibido</strong><p id="adminPaymentToastText"></p></div>
            <a href="<?= url('admin/pedidos_yape.php?status=pendiente') ?>">Revisar</a>
            <button type="button" aria-label="Cerrar notificación">×</button>
        </div>

        <main class="admin-main">
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?>" data-auto-dismiss>
                    <span><?= e($flashMessage['message']) ?></span>
                    <button type="button" aria-label="Cerrar">×</button>
                </div>
            <?php endif; ?>

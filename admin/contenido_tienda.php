<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';

require_admin();
if ((string) ($_SESSION['admin_role'] ?? 'admin') !== 'admin') {
    http_response_code(403);
    exit('Acceso restringido.');
}

function commercial_safe_url(mixed $value, bool $allowAnchor = false): string
{
    $value = trim((string) $value);
    if ($value === '') return '';
    $pattern = $allowAnchor ? '#^(?:https?://|/|#|assets/)#i' : '#^(?:https?://|/|assets/)#i';
    if (preg_match($pattern, $value) !== 1) {
        throw new InvalidArgumentException('La URL debe ser HTTPS o una ruta interna.');
    }
    return mb_substr($value, 0, 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_banner') {
            $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
            if ($title === '') throw new InvalidArgumentException('Escribe el título del banner.');
            $start = trim((string) ($_POST['starts_at'] ?? '')) ?: null;
            $end = trim((string) ($_POST['ends_at'] ?? '')) ?: null;
            if ($start && $end && strtotime($end) < strtotime($start)) {
                throw new InvalidArgumentException('La fecha final debe ser posterior a la inicial.');
            }
            $statement = db()->prepare(
                'INSERT INTO store_banners(title,subtitle,image_url,link_url,starts_at,ends_at,position,active) VALUES(?,?,?,?,?,?,?,?)'
            );
            $statement->execute([
                $title,
                mb_substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 240),
                commercial_safe_url($_POST['image_url'] ?? ''),
                commercial_safe_url($_POST['link_url'] ?? '', true),
                $start,
                $end,
                max(0, min(999, (int) ($_POST['position'] ?? 0))),
                isset($_POST['active']) ? 1 : 0,
            ]);
        } elseif ($action === 'toggle_banner') {
            $statement = db()->prepare('UPDATE store_banners SET active=1-active WHERE id=?');
            $statement->execute([(int) ($_POST['id'] ?? 0)]);
        } elseif ($action === 'delete_banner') {
            $statement = db()->prepare('DELETE FROM store_banners WHERE id=?');
            $statement->execute([(int) ($_POST['id'] ?? 0)]);
        } elseif ($action === 'feature_product') {
            $id = max(1, (int) ($_POST['product_id'] ?? 0));
            $statement = db()->prepare(
                'INSERT INTO store_featured_products(product_id,position,active) '
                . 'SELECT p.id,?,1 FROM products p WHERE p.id=? AND ' . master_product_visibility_sql('p')
                . ' ON DUPLICATE KEY UPDATE position=VALUES(position),active=1'
            );
            $statement->execute([max(0, (int) ($_POST['position'] ?? 0)), $id]);
            if ($statement->rowCount() === 0) {
                $eligible = db()->prepare('SELECT 1 FROM products p WHERE p.id=? AND ' . master_product_visibility_sql('p'));
                $eligible->execute([$id]);
                if (!$eligible->fetchColumn()) {
                    throw new InvalidArgumentException('El producto debe estar publicado, tener stock y no estar restringido para destacarlo.');
                }
            }
        } elseif ($action === 'remove_featured') {
            $statement = db()->prepare('DELETE FROM store_featured_products WHERE product_id=?');
            $statement->execute([(int) ($_POST['product_id'] ?? 0)]);
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }

        flash('success', 'Contenido de la tienda actualizado.');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
    }
    redirect('admin/contenido_tienda.php');
}

$pageTitle = 'Contenido de tienda';
$pageSubtitle = 'Banners, promociones y productos destacados';
$activePage = 'store-content';
$banners = [];
$featured = [];
$schemaReady = true;
try {
    $banners = db()->query('SELECT * FROM store_banners ORDER BY position,id DESC')->fetchAll();
    $featured = db()->query(
        'SELECT f.*,p.name,p.price,p.stock FROM store_featured_products f '
        . 'INNER JOIN products p ON p.id=f.product_id WHERE ' . master_product_visibility_sql('p')
        . ' ORDER BY f.position,p.name'
    )->fetchAll();
} catch (PDOException) {
    $schemaReady = false;
}
require __DIR__ . '/../includes/admin_header.php';
?>

<?php if (!$schemaReady): ?>
    <div class="alert alert-warning"><span>Importa la migración profesional para administrar el contenido.</span></div>
<?php endif; ?>

<div class="admin-two-columns store-content-layout">
    <section class="panel store-content-card">
        <div class="panel-heading"><div><h2>Nuevo banner</h2><p>Se mostrará en la portada dentro de su rango de fechas.</p></div></div>
        <form class="stack-form store-content-form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_banner">
            <label>Título<input name="title" maxlength="120" placeholder="Ej. Ofertas de la semana" required></label>
            <label>Subtítulo<input name="subtitle" maxlength="240" placeholder="Describe brevemente la promoción"></label>
            <label>Imagen (URL o ruta)<input name="image_url" maxlength="500" placeholder="https://… o assets/uploads/…"></label>
            <label>Enlace del botón<input name="link_url" maxlength="500" placeholder="#catalogo"></label>
            <div class="form-grid store-content-dates">
                <label>Inicio<input type="datetime-local" name="starts_at"></label>
                <label>Fin<input type="datetime-local" name="ends_at"></label>
            </div>
            <div class="store-content-options"><label>Orden de aparición<input type="number" name="position" min="0" value="0"></label><label class="check-line"><input type="checkbox" name="active" checked> Mostrar banner</label></div>
            <button class="btn btn-primary" type="submit">Guardar banner</button>
        </form>
    </section>

    <section class="panel store-content-card">
        <div class="panel-heading"><div><h2>Producto destacado</h2><p>Solo productos publicados, con stock y permitidos para la tienda.</p></div></div>
        <form class="stack-form store-content-form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="feature_product">
            <label>Buscar producto
                <input type="search" autocomplete="off" placeholder="Nombre, código o EAN" data-admin-product-search data-product-search-url="<?= e(url('api/admin_product_search.php')) ?>" data-product-target="#featuredProductId" data-product-results="#featuredProductResults" data-product-selected="#featuredProductSelected" data-product-require-public="1">
            </label>
            <input type="hidden" name="product_id" id="featuredProductId" required>
            <div id="featuredProductResults" class="picker-grid" aria-live="polite"></div>
            <small id="featuredProductSelected">Escribe al menos 2 caracteres para buscar.</small>
            <label>Orden de aparición<input type="number" name="position" min="0" value="0"></label>
            <button class="btn btn-primary" type="submit">Destacar producto</button>
        </form>
        <div class="settings-list">
            <?php foreach ($featured as $row): ?>
                <div>
                    <span><strong><?= e($row['name']) ?></strong><small><?= money($row['price']) ?> · posición <?= (int) $row['position'] ?></small></span>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_featured">
                        <input type="hidden" name="product_id" value="<?= (int) $row['product_id'] ?>">
                        <button>Quitar</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="panel store-content-banners">
    <div class="panel-heading"><div><h2>Banners programados</h2><p>Activa, pausa o elimina campañas.</p></div></div>
    <div class="store-banner-admin-grid">
        <?php foreach ($banners as $banner): ?>
            <article>
                <?php if ($banner['image_url']): ?><img src="<?= e(image_src($banner['image_url'])) ?>" alt="" loading="lazy"><?php endif; ?>
                <div>
                    <span class="visibility <?= (int) $banner['active'] === 1 ? 'is-active' : '' ?>"><?= (int) $banner['active'] === 1 ? 'Activo' : 'Pausado' ?></span>
                    <h3><?= e($banner['title']) ?></h3><p><?= e($banner['subtitle']) ?></p>
                    <small><?= e($banner['starts_at'] ?: 'Sin inicio') ?> — <?= e($banner['ends_at'] ?: 'Sin fin') ?></small>
                    <div class="row-actions">
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_banner"><input type="hidden" name="id" value="<?= (int) $banner['id'] ?>"><button class="btn btn-secondary">Activar/Pausar</button></form>
                        <form method="post" onsubmit="return confirm('¿Eliminar este banner?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_banner"><input type="hidden" name="id" value="<?= (int) $banner['id'] ?>"><button class="btn btn-danger">Eliminar</button></form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$banners): ?><div class="empty-mini">Aún no hay banners creados.</div><?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>

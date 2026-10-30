<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';

require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}
verify_csrf();

/** @return array<string,int|float|string> */
function master_bulk_request_changes(array $post): array
{
    $operation = (string)($post['bulk_action'] ?? '');
    $value = $post['bulk_value'] ?? '';
    $input = [];
    if ($operation === 'activate') { $input['active'] = '1'; $input['sale_enabled'] = '1'; }
    elseif ($operation === 'deactivate') $input['sale_enabled'] = '0';
    elseif ($operation === 'publish') { $input['active'] = '1'; $input['sale_enabled'] = '1'; }
    elseif ($operation === 'unpublish') $input['sale_enabled'] = '0';
    elseif (in_array($operation, ['margin_pct', 'min_stock', 'price'], true)) $input[$operation] = $value;
    else throw new InvalidArgumentException('Selecciona una acción masiva válida.');
    return master_bulk_changes($input);
}

/** @param array<string,int|float|string> $changes */
function master_bulk_changes_label(array $changes): string
{
    $labels = ['active' => 'visibilidad', 'sale_enabled' => 'venta', 'margin_pct' => 'margen', 'min_stock' => 'stock mínimo', 'price' => 'precio'];
    $parts = [];
    foreach ($changes as $field => $value) $parts[] = ($labels[$field] ?? $field) . ': ' . (string)$value;
    return implode(' · ', $parts);
}

$action = (string)($_POST['action'] ?? '');
try {
    if ($action === 'preview') {
        $scope = (string)($_POST['selection_scope'] ?? 'selected');
        $ids = $scope === 'all' ? master_bulk_all_ids(db()) : master_bulk_selection($_POST);
        if (!$ids) throw new InvalidArgumentException($scope === 'all' ? 'La base de datos no contiene productos.' : 'Selecciona al menos un producto.');
        $changes = master_bulk_request_changes($_POST);
        $rows = master_bulk_master_rows(db(), $ids);
        master_bulk_assert_publishable($rows, $changes);
        $token = master_bulk_store_confirmation($ids, $changes);

        $pageTitle = 'Confirmar actualización masiva';
        $pageSubtitle = 'Revisa el alcance antes de aplicar los cambios';
        $activePage = 'all-products';
        require __DIR__ . '/../includes/admin_header.php';
        ?>
        <section class="panel">
            <h2>Confirmar actualización masiva</h2>
            <p>Se modificarán <strong><?= number_format(count($rows)) ?></strong> producto<?= count($rows) === 1 ? '' : 's' ?><?= $scope === 'all' ? ' de toda la base de datos' : '' ?>.</p>
            <p><strong>Cambios:</strong> <?= e(master_bulk_changes_label($changes)) ?></p>
            <form method="post" action="<?= url('admin/productos_masivo.php') ?>" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="selection_token" value="<?= e($token) ?>">
                <label class="check-line"><input type="checkbox" name="confirm" value="yes" required> Confirmo que deseo modificar <?= count($rows) ?> producto<?= count($rows) === 1 ? '' : 's' ?>.</label>
                <div class="row-actions"><a class="btn btn-secondary" href="<?= url('admin/todos_productos.php') ?>">Cancelar</a><button class="btn btn-primary" type="submit">Aplicar cambios</button></div>
            </form>
        </section>
        <?php
        require __DIR__ . '/../includes/admin_footer.php';
        exit;
    }

    if ($action !== 'apply' || (string)($_POST['confirm'] ?? '') !== 'yes') {
        throw new InvalidArgumentException('Debes confirmar la actualización masiva.');
    }
    $confirmation = master_bulk_load_confirmation((string)($_POST['selection_token'] ?? ''));
    master_bulk_apply(db(), $confirmation['ids'], $confirmation['changes'], (int)($_SESSION['admin_id'] ?? 0) ?: null);
    flash('success', count($confirmation['ids']) . ' producto(s) maestro(s) actualizados.');
} catch (Throwable $exception) {
    flash('danger', $exception->getMessage());
}
redirect('admin/todos_productos.php');

<?php
declare(strict_types=1);

function master_product_visibility_sql(string $alias='p'): string
{
    return "$alias.catalog_scope = 'master' AND $alias.active = 1 AND $alias.sale_enabled = 1 AND $alias.restricted = 0 AND $alias.stock > 0 AND $alias.price > 0";
}

/** @return array{active:int,sale_enabled:int} */
function master_product_publication_fields(bool $hiddenFromStore, bool $restricted): array
{
    if ($restricted) return ['active' => 0, 'sale_enabled' => 0];
    return ['active' => 1, 'sale_enabled' => $hiddenFromStore ? 0 : 1];
}

function master_product_is_manually_hidden(array $product): bool
{
    return (int)($product['sale_enabled'] ?? 0) === 0 && (int)($product['stock'] ?? 0) > 0;
}

function master_product_catalog_visibility_sql(string $alias='p'): string
{
    return "$alias.catalog_scope = 'master' AND $alias.restricted = 0 AND COALESCE(NULLIF($alias.price, 0), $alias.suggested_price) > 0";
}

function sale_eligible_product(array $product, int $quantity=1): bool
{
    return $quantity > 0
        && (int) ($product['active'] ?? 0) === 1
        && (int) ($product['sale_enabled'] ?? 0) === 1
        && (int) ($product['restricted'] ?? 0) === 0
        && (int) ($product['stock'] ?? 0) >= $quantity
        && (float) ($product['price'] ?? 0) > 0;
}

function master_suggested_price(?float $supplierPrice, ?float $margin, float $globalMargin=25.0): ?float
{
    if ($supplierPrice === null || $supplierPrice <= 0) return null;
    $pct = max(0.0, min(500.0, $margin ?? $globalMargin));
    return round($supplierPrice * (1 + $pct / 100), 2);
}

function master_product_status(array $p): string
{
    if ((int)($p['restricted'] ?? 0) === 1) return 'restricted';
    if ((int)($p['active'] ?? 0) === 1 && (int)($p['sale_enabled'] ?? 0) === 1 && (int)($p['stock'] ?? 0) > 0 && (float)($p['price'] ?? 0) > 0) return 'published';
    if ((int)($p['active'] ?? 0) === 1 && (int)($p['stock'] ?? 0) <= 0) return 'out_of_stock';
    if ((float)($p['cost_price'] ?? 0) > 0 && (float)($p['price'] ?? 0) > 0) return 'ready';
    return 'pending';
}

/** @return array{key:string,label:string} */
function master_product_simple_status(array $product): array
{
    if ((int) ($product['stock'] ?? 0) <= 0) {
        return ['key' => 'out_of_stock', 'label' => 'Sin stock'];
    }
    if ((int) ($product['restricted'] ?? 0) === 1
        || (int) ($product['active'] ?? 0) !== 1
        || (int) ($product['sale_enabled'] ?? 0) !== 1
        || (float) ($product['price'] ?? 0) <= 0) {
        return ['key' => 'hidden', 'label' => 'Oculto'];
    }
    return ['key' => 'visible', 'label' => 'Visible'];
}

function master_catalog_filters(array $input): array
{
    $rowsAllowed = [25, 50, 100];
    $sortAllowed = ['name', 'source_name', 'category', 'supplier_price', 'cost_price', 'price', 'stock', 'updated_at'];
    $dirAllowed = ['asc', 'desc'];
    $statusAllowed = ['', 'pending', 'ready', 'published', 'out_of_stock', 'restricted'];
    $rows = (int)($input['rows'] ?? 25);
    $page = max(1, (int)($input['page'] ?? 1));
    $sort = (string)($input['sort'] ?? 'name');
    $dir = strtolower((string)($input['dir'] ?? 'asc'));
    $status = (string)($input['status'] ?? '');
    return [
        'q' => mb_substr(trim((string)($input['q'] ?? '')), 0, 100),
        'source' => mb_substr(trim((string)($input['source'] ?? '')), 0, 100),
        'category' => mb_substr(trim((string)($input['category'] ?? '')), 0, 100),
        'min_price' => is_numeric($input['min_price'] ?? null) ? max(0.0, (float)$input['min_price']) : null,
        'max_price' => is_numeric($input['max_price'] ?? null) ? max(0.0, (float)$input['max_price']) : null,
        'min_stock' => is_numeric($input['min_stock'] ?? null) ? max(0, (int)$input['min_stock']) : null,
        'max_stock' => is_numeric($input['max_stock'] ?? null) ? max(0, (int)$input['max_stock']) : null,
        'rows' => in_array($rows, $rowsAllowed, true) ? $rows : 25,
        'page' => $page,
        'status' => in_array($status, $statusAllowed, true) ? $status : '',
        'sort' => in_array($sort, $sortAllowed, true) ? $sort : 'name',
        'dir' => in_array($dir, $dirAllowed, true) ? $dir : 'asc',
    ];
}

/**
 * Builds the master-only filter clause. Values are intentionally kept out of
 * the SQL text and must be bound with master_catalog_bind_params().
 */
function master_catalog_where(array $filters): array
{
    $filters = master_catalog_filters($filters);
    $where = ["catalog_scope = 'master'"];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = "CONCAT_WS(' ', name, code, category, brand, ean, presentation) LIKE :q";
        $params['q'] = '%' . $filters['q'] . '%';
    }
    if ($filters['source'] !== '') {
        $where[] = 'source_name = :source';
        $params['source'] = $filters['source'];
    }
    if ($filters['category'] !== '') {
        $where[] = 'category = :category';
        $params['category'] = $filters['category'];
    }
    if ($filters['min_price'] !== null) {
        $where[] = 'price >= :min_price';
        $params['min_price'] = $filters['min_price'];
    }
    if ($filters['max_price'] !== null) {
        $where[] = 'price <= :max_price';
        $params['max_price'] = $filters['max_price'];
    }
    if ($filters['min_stock'] !== null) {
        $where[] = 'stock >= :min_stock';
        $params['min_stock'] = $filters['min_stock'];
    }
    if ($filters['max_stock'] !== null) {
        $where[] = 'stock <= :max_stock';
        $params['max_stock'] = $filters['max_stock'];
    }

    $statusSql = [
        'published' => 'active = 1 AND sale_enabled = 1 AND restricted = 0 AND stock > 0 AND price > 0',
        'out_of_stock' => 'active = 1 AND restricted = 0 AND stock <= 0',
        'restricted' => 'restricted = 1',
        'ready' => 'restricted = 0 AND cost_price > 0 AND price > 0 AND NOT (active = 1 AND sale_enabled = 1 AND stock > 0 AND price > 0) AND NOT (active = 1 AND stock <= 0)',
        'pending' => 'restricted = 0 AND (cost_price <= 0 OR price <= 0)',
    ];
    if ($filters['status'] !== '') $where[] = $statusSql[$filters['status']];

    return ['sql' => ' WHERE ' . implode(' AND ', $where), 'params' => $params];
}

function master_catalog_bind_params(PDOStatement $statement, array $params): void
{
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function fetch_master_catalog(PDO $pdo, array $filters): array
{
    $filters = master_catalog_filters($filters);
    $where = master_catalog_where($filters);
    $count = $pdo->prepare('SELECT COUNT(*) FROM products' . $where['sql']);
    master_catalog_bind_params($count, $where['params']);
    $count->execute();
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $filters['rows']));
    $page = min($filters['page'], $pages);
    $order = [
        'name' => 'name', 'source_name' => 'source_name', 'category' => 'category',
        'supplier_price' => 'supplier_price', 'cost_price' => 'cost_price',
        'price' => 'price', 'stock' => 'stock', 'updated_at' => 'source_updated_at',
    ][$filters['sort']];
    $sql = 'SELECT id, code, name, category, cost_price, price, stock, min_stock, image_url, active, '
        . 'source_name, ean, brand, presentation, supplier_price, suggested_price, margin_pct, '
        . 'sale_enabled, restricted, source_updated_at FROM products' . $where['sql']
        . ' ORDER BY ' . $order . ' ' . strtoupper($filters['dir']) . ', id ASC LIMIT :limit OFFSET :offset';
    $statement = $pdo->prepare($sql);
    master_catalog_bind_params($statement, $where['params']);
    $statement->bindValue(':limit', $filters['rows'], PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * $filters['rows'], PDO::PARAM_INT);
    $statement->execute();

    return ['items' => $statement->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
}

function master_catalog_options(PDO $pdo): array
{
    return [
        'sources' => $pdo->query("SELECT DISTINCT source_name FROM products WHERE catalog_scope = 'master' ORDER BY source_name")->fetchAll(PDO::FETCH_COLUMN),
        'categories' => $pdo->query("SELECT DISTINCT category FROM products WHERE catalog_scope = 'master' AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN),
    ];
}

function master_catalog_summary(PDO $pdo): array
{
    return $pdo->query("SELECT COUNT(*) total,
      SUM(active=1 AND sale_enabled=1 AND restricted=0 AND stock>0 AND price>0) published,
      SUM(stock<=min_stock) low_stock,
      SUM(cost_price<=0) missing_cost,
      SUM(price<=0) missing_price,
      COALESCE(SUM(cost_price<=0 OR price<=0),0) missing_pricing,
      COALESCE(SUM(cost_price*stock),0) inventory_cost,
      COALESCE(SUM((price-cost_price)*stock),0) potential_profit
      FROM products WHERE catalog_scope = 'master'")->fetch() ?: [];
}

/** @return list<int> */
function master_bulk_selection(array $post): array
{
    $raw = $post['ids'] ?? [];
    if (!is_array($raw)) return [];
    $ids = [];
    foreach ($raw as $value) {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string)$value)) continue;
        $id = (int)$value;
        if ($id > 0) $ids[$id] = $id;
    }
    return array_values($ids);
}

/** @return list<int> */
function master_bulk_all_ids(PDO $pdo): array
{
    $statement = $pdo->query("SELECT id FROM products WHERE catalog_scope = 'master' AND restricted = 0 ORDER BY id");
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function master_bulk_money_cents(mixed $value): string
{
    if (!is_scalar($value)) throw new InvalidArgumentException('El precio no es válido.');
    $raw = trim((string)$value);
    if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $raw, $matches)) {
        throw new InvalidArgumentException('El precio no es válido.');
    }
    if (strlen($matches[1]) > 6) throw new InvalidArgumentException('El precio no es válido.');
    $fraction = $matches[2] ?? '';
    $cents = (int)substr(str_pad($fraction, 2, '0'), 0, 2);
    if (isset($fraction[2]) && (int)$fraction[2] >= 5) $cents++;
    $totalCents = ((int)$matches[1] * 100) + $cents;
    if ($totalCents > 99999999) throw new InvalidArgumentException('El precio no es válido.');
    return intdiv($totalCents, 100) . '.' . str_pad((string)($totalCents % 100), 2, '0', STR_PAD_LEFT);
}

/** @return array<string,int|float|string> */
function master_bulk_changes(array $post): array
{
    $changes = [];
    foreach (['active', 'sale_enabled'] as $field) {
        if (!array_key_exists($field, $post) || $post[$field] === '') continue;
        if (!is_scalar($post[$field]) || !in_array((string)$post[$field], ['0', '1'], true)) {
            throw new InvalidArgumentException('El campo ' . $field . ' debe ser 0 o 1.');
        }
        $changes[$field] = (int)$post[$field];
    }
    if (array_key_exists('margin_pct', $post) && $post['margin_pct'] !== '') {
        if (!is_scalar($post['margin_pct']) || !is_numeric($post['margin_pct']) || (float)$post['margin_pct'] < 0 || (float)$post['margin_pct'] > 500) {
            throw new InvalidArgumentException('El margen debe estar entre 0 y 500.');
        }
        $changes['margin_pct'] = (float)$post['margin_pct'];
    }
    if (array_key_exists('min_stock', $post) && $post['min_stock'] !== '') {
        if (!is_scalar($post['min_stock']) || filter_var($post['min_stock'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]) === false) {
            throw new InvalidArgumentException('El stock mínimo no es válido.');
        }
        $changes['min_stock'] = (int)$post['min_stock'];
    }
    if (array_key_exists('price', $post) && $post['price'] !== '') {
        $price = master_bulk_money_cents($post['price']);
        if ($price === '0.00' && preg_match('/[1-9]/', (string)$post['price'])) {
            throw new InvalidArgumentException('El precio positivo es demasiado pequeño; debe redondear al menos a 0.01.');
        }
        $changes['price'] = $price;
    }
    if (!$changes) throw new InvalidArgumentException('Selecciona un valor para actualizar.');
    return $changes;
}

/** @param list<int> $ids @param array<string,int|float|string> $changes */
function master_bulk_confirmation_token(array $ids, array $changes, int $expiresAt, string $nonce, string $secret): string
{
    $ids = master_bulk_selection(['ids' => $ids]);
    $changes = master_bulk_changes($changes);
    if (!$ids) throw new InvalidArgumentException('Selecciona al menos un producto.');
    $payload = json_encode(['ids' => $ids, 'changes' => $changes, 'expires_at' => $expiresAt, 'nonce' => $nonce], JSON_THROW_ON_ERROR);
    return $nonce . '.' . hash_hmac('sha256', $payload, $secret);
}

/** @param list<int> $ids @param array<string,int|float|string> $changes */
function master_bulk_confirmation_is_valid(string $token, array $ids, array $changes, int $expiresAt, string $nonce, int $now, string $secret): bool
{
    if ($expiresAt <= $now) return false;
    return hash_equals(master_bulk_confirmation_token($ids, $changes, $expiresAt, $nonce, $secret), $token);
}

/** @param list<int> $ids @param array<string,int|float|string> $changes */
function master_bulk_store_confirmation(array $ids, array $changes): string
{
    $ids = master_bulk_selection(['ids' => $ids]);
    $changes = master_bulk_changes($changes);
    if (!$ids) throw new InvalidArgumentException('Selecciona al menos un producto.');
    $expiresAt = time() + 600;
    $nonce = bin2hex(random_bytes(24));
    $token = master_bulk_confirmation_token($ids, $changes, $expiresAt, $nonce, csrf_token());
    $_SESSION['master_bulk_confirmation'] = [
        'token' => $token,
        'nonce' => $nonce,
        'ids' => $ids,
        'changes' => $changes,
        'expires_at' => $expiresAt,
    ];
    return $token;
}

/** @return array{ids:list<int>,changes:array<string,int|float|string>,expires_at:int} */
function master_bulk_load_confirmation(string $token): array
{
    $confirmation = $_SESSION['master_bulk_confirmation'] ?? null;
    if (!is_array($confirmation) || !is_string($confirmation['nonce'] ?? null)) {
        throw new RuntimeException('La confirmación no es válida. Vuelve a revisar los cambios.');
    }
    $expiresAt = (int)($confirmation['expires_at'] ?? 0);
    if ($expiresAt <= time()) {
        unset($_SESSION['master_bulk_confirmation']);
        throw new RuntimeException('La confirmación expiró. Vuelve a generar la vista previa.');
    }
    if (!is_string($confirmation['token'] ?? null) || !hash_equals($confirmation['token'], $token)) {
        throw new RuntimeException('La confirmación no es válida. Vuelve a revisar los cambios.');
    }
    $ids = master_bulk_selection(['ids' => $confirmation['ids'] ?? []]);
    $changes = master_bulk_changes(is_array($confirmation['changes'] ?? null) ? $confirmation['changes'] : []);
    if (!$ids) throw new RuntimeException('La confirmación no contiene productos válidos.');
    if (!master_bulk_confirmation_is_valid($token, $ids, $changes, $expiresAt, $confirmation['nonce'], time(), csrf_token())) {
        throw new RuntimeException('La confirmación fue alterada. Vuelve a generar la vista previa.');
    }
    unset($_SESSION['master_bulk_confirmation']);
    return ['ids' => $ids, 'changes' => $changes, 'expires_at' => (int)$confirmation['expires_at']];
}

/** @param array<string,mixed> $row @param array<string,int|float|string> $changes */
function master_bulk_publish_eligible(array $row, array $changes): bool
{
    $changes = master_bulk_changes($changes);
    $active = (int)($changes['active'] ?? $row['active']);
    $saleEnabled = (int)($changes['sale_enabled'] ?? $row['sale_enabled']);
    if ($active !== 1 || $saleEnabled !== 1) return true;
    return (int)$row['restricted'] === 0;
}

/** @param array<string,int|float|string> $changes @return array{sql:string,params:list<int|float|string>} */
function master_bulk_publish_guard(array $changes): array
{
    $changes = master_bulk_changes($changes);
    $params = [];
    $next = static function (string $column) use ($changes, &$params): string {
        if (!array_key_exists($column, $changes)) return $column;
        $params[] = $changes[$column];
        return '?';
    };
    $active = $next('active');
    $saleEnabled = $next('sale_enabled');
    return [
        'sql' => ' AND (NOT (' . $active . ' = 1 AND ' . $saleEnabled . ' = 1)'
            . ' OR restricted = 0)',
        'params' => $params,
    ];
}

/** @param list<int> $ids @return list<array<string,mixed>> */
function master_bulk_master_rows(PDO $pdo, array $ids, bool $forUpdate = false): array
{
    if (!$ids) throw new InvalidArgumentException('Selecciona al menos un producto.');
    $sql = 'SELECT id, active, sale_enabled, restricted, stock, price, cost_price, margin_pct, min_stock FROM products'
        . " WHERE catalog_scope = 'master' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $statement = $pdo->prepare($sql);
    $statement->execute($ids);
    $byId = [];
    foreach ($statement->fetchAll() as $row) $byId[(int)$row['id']] = $row;
    if (count($byId) !== count($ids)) {
        throw new RuntimeException('La selección contiene productos que no pertenecen al catálogo maestro.');
    }
    return array_map(static fn(int $id): array => $byId[$id], $ids);
}

/** @param list<array<string,mixed>> $rows @param array<string,int|float|string> $changes */
function master_bulk_assert_publishable(array $rows, array $changes): void
{
    foreach ($rows as $row) {
        if (!master_bulk_publish_eligible($row, $changes)) {
            throw new RuntimeException('No se puede publicar: todos los productos deben estar permitidos, tener stock, precio y costo positivos.');
        }
    }
}

/** @param list<array<string,mixed>> $rows @param array<string,int|float|string> $changes */
function master_bulk_row_needs_changes(array $row, array $changes): bool
{
    foreach ($changes as $column => $value) {
        if ($column === 'margin_pct' && $row[$column] === null) return true;
        if ($column === 'price' && master_bulk_money_cents($row[$column]) !== $value) return true;
        if ($column !== 'price' && (float)$row[$column] !== (float)$value) return true;
    }
    return false;
}

/** @param list<array<string,mixed>> $rows @param array<string,int|float|string> $changes */
function master_bulk_assert_changes_effective(array $rows, array $changes): void
{
    foreach ($rows as $row) if (master_bulk_row_needs_changes($row, $changes)) return;
    throw new RuntimeException('Todos los productos seleccionados ya tienen los valores solicitados.');
}

/** @param list<int> $ids @param array<string,int|float|string> $changes */
function master_bulk_apply(PDO $pdo, array $ids, array $changes, ?int $actorId): void
{
    $ids = master_bulk_selection(['ids' => $ids]);
    $changes = master_bulk_changes($changes);
    $pdo->beginTransaction();
    try {
        $rows = master_bulk_master_rows($pdo, $ids, true);
        master_bulk_assert_publishable($rows, $changes);
        master_bulk_assert_changes_effective($rows, $changes);
        $ids = array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            array_filter($rows, static fn(array $row): bool => master_bulk_row_needs_changes($row, $changes))
        ));
        $allowed = ['active', 'sale_enabled', 'margin_pct', 'min_stock', 'price'];
        $set = [];
        $values = [];
        foreach ($changes as $column => $value) {
            if (!in_array($column, $allowed, true)) throw new InvalidArgumentException('Campo no permitido.');
            $set[] = $column . ' = ?';
            $values[] = $value;
        }
        $publishGuard = master_bulk_publish_guard($changes);
        foreach (array_chunk($ids, 500) as $batch) {
            $sql = 'UPDATE products SET ' . implode(', ', $set)
                . " WHERE catalog_scope = 'master' AND id IN (" . implode(',', array_fill(0, count($batch), '?')) . ')'
                . $publishGuard['sql'];
            $statement = $pdo->prepare($sql);
            $statement->execute(array_merge($values, $batch, $publishGuard['params']));
            if ($statement->rowCount() !== count($batch)) {
                throw new RuntimeException('El estado de uno o más productos cambió durante la actualización.');
            }
        }
        $details = json_encode(['count' => count($ids), 'changes' => $changes], JSON_THROW_ON_ERROR);
        $log = $pdo->prepare('INSERT INTO activity_log (actor_type, actor_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)');
        $log->execute(['admin', $actorId, 'master_bulk_update', 'products', null, substr($details, 0, 500)]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

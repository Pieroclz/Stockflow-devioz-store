<?php
declare(strict_types=1);

require_once __DIR__ . '/master_catalog.php';

/** @return array<int,array{id:int,quantity:int}> */
function combo_builder_normalize_items(array $items): array
{
    $merged = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = max(0, (int) ($item['id'] ?? $item['product_id'] ?? 0));
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        if ($id <= 0 || $quantity <= 0 || $quantity > 999) {
            throw new InvalidArgumentException('Revisa las cantidades del combo.');
        }
        $merged[$id] = ($merged[$id] ?? 0) + $quantity;
    }
    if (!$merged) throw new InvalidArgumentException('Agrega al menos un producto al combo.');
    return array_map(static fn (int $id, int $quantity): array => ['id' => $id, 'quantity' => $quantity], array_keys($merged), array_values($merged));
}

/** @return array{cost:float,max_stock:int,items:array<int,array<string,mixed>>} */
function combo_builder_quote(array $products, array $items): array
{
    $items = combo_builder_normalize_items($items);
    $cost = 0.0;
    $maxStock = PHP_INT_MAX;
    $out = [];
    foreach ($items as $item) {
        $id = (int) $item['id'];
        if (!isset($products[$id]) || !is_array($products[$id])) {
            throw new InvalidArgumentException('Un producto del combo ya no existe.');
        }
        $product = $products[$id];
        if ((int) ($product['restricted'] ?? 0) === 1 || (int) ($product['active'] ?? 1) !== 1 || (int) ($product['sale_enabled'] ?? 1) !== 1) {
            throw new InvalidArgumentException('Un producto del combo no esta disponible.');
        }
        $quantity = (int) $item['quantity'];
        $stock = max(0, (int) ($product['stock'] ?? 0));
        $unitCost = round((float) ($product['cost_price'] ?? 0), 2);
        $cost += $unitCost * $quantity;
        $maxStock = min($maxStock, intdiv($stock, $quantity));
        $out[] = ['id' => $id, 'quantity' => $quantity, 'name' => (string) ($product['name'] ?? ''), 'cost' => $unitCost];
    }
    return ['cost' => round($cost, 2), 'max_stock' => $maxStock === PHP_INT_MAX ? 0 : $maxStock, 'items' => $out];
}

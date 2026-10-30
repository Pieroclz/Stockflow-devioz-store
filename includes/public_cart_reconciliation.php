<?php
declare(strict_types=1);

/** @return list<array{type:string,id:int,quantity:int}> */
function cart_reconciliation_request_items(array $items): array
{
    $normalized = [];
    foreach (array_slice($items, 0, 40) as $item) {
        if (!is_array($item)) continue;
        $type = (string) ($item['type'] ?? '');
        if (!in_array($type, ['product', 'product_pack', 'combo'], true)) continue;
        $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if ($id === false || $quantity === false) continue;

        $key = $type . ':' . $id;
        if (isset($normalized[$key])) {
            $normalized[$key]['quantity'] += $quantity;
            if ($normalized[$key]['quantity'] > 99) {
                unset($normalized[$key]);
            }
            continue;
        }
        $normalized[$key] = ['type' => $type, 'id' => (int) $id, 'quantity' => (int) $quantity];
    }

    return array_values($normalized);
}

/**
 * Allocate normalized cart candidates against a single per-product stock ledger.
 * Candidates are processed in their supplied order, so a trimmed line never
 * lets a later direct product, pack, or combo overdraw any shared component.
 *
 * @param list<array<string,mixed>> $candidates
 * @param array<int|string,int|string> $availableStock
 * @return array{items:list<array<string,mixed>>,remaining_stock:array<int,int>}
 */
function cart_reconciliation_allocate_candidates(array $candidates, array $availableStock): array
{
    $remainingStock = [];
    foreach ($availableStock as $productId => $stock) {
        $id = filter_var((string) $productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $units = filter_var($stock, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false || $units === false) continue;
        $remainingStock[(int) $id] = (int) $units;
    }

    $accepted = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) continue;
        $requested = filter_var($candidate['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
        $rawRequirements = $candidate['requirements'] ?? null;
        if ($requested === false || !is_array($rawRequirements) || !$rawRequirements) continue;

        $requirements = [];
        $valid = true;
        foreach ($rawRequirements as $productId => $unitsPerItem) {
            $id = filter_var((string) $productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $units = filter_var($unitsPerItem, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $units === false || !array_key_exists((int) $id, $remainingStock)) {
                $valid = false;
                break;
            }
            $requirements[(int) $id] = ($requirements[(int) $id] ?? 0) + (int) $units;
        }
        if (!$valid || !$requirements) continue;

        $available = PHP_INT_MAX;
        foreach ($requirements as $productId => $unitsPerItem) {
            $available = min($available, intdiv($remainingStock[$productId], $unitsPerItem));
        }
        if (array_key_exists('line_stock_limit', $candidate)) {
            $lineLimit = filter_var($candidate['line_stock_limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($lineLimit === false) continue;
            $available = min($available, (int) $lineLimit);
        }

        $quantity = min((int) $requested, $available);
        if ($quantity < 1) continue;
        foreach ($requirements as $productId => $unitsPerItem) {
            $remainingStock[$productId] -= $quantity * $unitsPerItem;
        }

        $candidate['requirements'] = $requirements;
        $candidate['quantity'] = $quantity;
        $candidate['available'] = $available;
        $accepted[] = $candidate;
    }

    return ['items' => $accepted, 'remaining_stock' => $remainingStock];
}

/** @return array<string,mixed> */
function cart_reconciliation_product_payload(array $product, string $type, int $quantity, int $available): array
{
    $isPack = $type === 'product_pack';
    $packSize = max(1, (int) ($product['units_per_pack'] ?? 1));
    return [
        'type' => $type,
        'kind' => 'product',
        'id' => (int) $product['id'],
        'code' => (string) $product['code'],
        'name' => (string) $product['name'],
        'category' => (string) $product['category'],
        'description' => (string) ($product['description'] ?? ''),
        'price' => (float) ($isPack ? $product['pack_price'] : $product['price']),
        'stock' => $available,
        'image_url' => image_src((string) ($product['image_url'] ?? '')),
        'icon' => category_icon((string) $product['category']),
        'presentation' => $isPack ? 'paquete' : 'unidad',
        'presentation_label' => $isPack ? 'Pack de ' . $packSize : 'Unidad',
        'units_per_item' => $isPack ? $packSize : 1,
        'quantity' => $quantity,
        'restricted' => false,
    ];
}

/** @return array<string,mixed> */
function cart_reconciliation_combo_payload(array $combo, int $quantity, int $available): array
{
    return [
        'type' => 'combo',
        'kind' => 'combo',
        'id' => (int) $combo['id'],
        'code' => 'COMBO-' . str_pad((string) $combo['id'], 3, '0', STR_PAD_LEFT),
        'name' => (string) $combo['name'],
        'category' => 'Combos',
        'description' => (string) ($combo['description'] ?? ''),
        'price' => (float) $combo['price'],
        'stock' => $available,
        'image_url' => image_src((string) ($combo['image_url'] ?? '')),
        'icon' => '✦',
        'presentation' => 'unidad',
        'presentation_label' => 'Combo completo',
        'units_per_item' => 1,
        'quantity' => $quantity,
        'restricted' => false,
    ];
}

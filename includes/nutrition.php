<?php
declare(strict_types=1);

function nutrition_default_applicability(string $name, string $category): string
{
    $text = mb_strtolower($name . ' ' . $category);
    foreach (['mascota', 'perro', 'gato', 'higiene', 'limpieza', 'detergente', 'shampoo', 'jabon', 'lejia', 'desinfectante'] as $blocked) {
        if (str_contains($text, $blocked)) return 'non_food';
    }
    foreach (['bebida', 'gaseosa', 'agua', 'jugo', 'snack', 'galleta', 'chocolate', 'arroz', 'avena', 'leche', 'cafe', 'comida', 'abarrote', 'dulce', 'pan', 'cereal'] as $food) {
        if (str_contains($text, $food)) return 'food';
    }
    return 'review';
}

function nutrition_nullable_number(mixed $value): ?float
{
    if ($value === null || $value === '') return null;
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) return null;
    return round((float) $value, 2);
}

/** @return array<string,mixed> */
function nutrition_normalize(array $row): array
{
    $applicability = in_array($row['applicability'] ?? '', ['food', 'non_food', 'review'], true)
        ? (string) $row['applicability']
        : 'review';
    $sourceType = in_array($row['source_type'] ?? null, ['label', 'open_food_facts', 'ins', 'usda', 'manual'], true)
        ? (string) $row['source_type']
        : null;
    $confidence = in_array($row['confidence'] ?? null, ['verified', 'reference'], true)
        ? (string) $row['confidence']
        : null;
    if ($confidence === 'verified' && $sourceType !== 'label') $confidence = 'reference';

    return [
        'applicability' => $applicability,
        'energy_kcal_100g' => nutrition_nullable_number($row['energy_kcal_100g'] ?? null),
        'serving_size' => nutrition_nullable_number($row['serving_size'] ?? null),
        'serving_unit' => in_array($row['serving_unit'] ?? null, ['g', 'ml', 'unit'], true) ? (string) $row['serving_unit'] : null,
        'energy_kcal_serving' => nutrition_nullable_number($row['energy_kcal_serving'] ?? null),
        'protein_g' => nutrition_nullable_number($row['protein_g'] ?? null),
        'carbohydrate_g' => nutrition_nullable_number($row['carbohydrate_g'] ?? null),
        'fat_g' => nutrition_nullable_number($row['fat_g'] ?? null),
        'source_type' => $sourceType,
        'source_ref' => mb_substr(trim((string) ($row['source_ref'] ?? '')), 0, 255),
        'confidence' => $confidence,
    ];
}

/** @return array{known:bool,kcal_total:?float,kcal_serving:?float,basis:string,status:string} */
function nutrition_calculate(array $nutrition, ?float $packageAmount, string $packageUnit, int $quantity): array
{
    $nutrition = nutrition_normalize($nutrition);
    if ($nutrition['applicability'] === 'non_food') {
        return ['known' => false, 'kcal_total' => null, 'kcal_serving' => null, 'basis' => 'no_aplica', 'status' => 'no_aplica'];
    }
    if ($nutrition['applicability'] !== 'food') {
        return ['known' => false, 'kcal_total' => null, 'kcal_serving' => null, 'basis' => 'revision', 'status' => 'sin_informacion'];
    }

    $serving = $nutrition['energy_kcal_serving'];
    $basis = 'porcion';
    if ($serving === null && $nutrition['energy_kcal_100g'] !== null && $packageAmount !== null && $packageAmount > 0 && in_array($packageUnit, ['g', 'ml'], true)) {
        $serving = round($nutrition['energy_kcal_100g'] * $packageAmount / 100, 2);
        $basis = $packageUnit === 'ml' ? 'envase_ml' : 'envase_g';
    }
    if ($serving === null) {
        return ['known' => false, 'kcal_total' => null, 'kcal_serving' => null, 'basis' => 'sin_dato', 'status' => 'sin_informacion'];
    }
    $qty = max(1, $quantity);
    return ['known' => true, 'kcal_total' => round($serving * $qty, 2), 'kcal_serving' => round($serving, 2), 'basis' => $basis, 'status' => 'con_dato'];
}

/** @return array{known_kcal:float,known_items:int,unknown_items:int,total_items:int} */
function nutrition_summarize(array $items): array
{
    $known = 0.0;
    $knownItems = 0;
    $unknown = 0;
    foreach ($items as $item) {
        $nutrition = is_array($item['nutrition'] ?? null) ? $item['nutrition'] : [];
        $value = $nutrition['energy_kcal_serving'] ?? null;
        if (($nutrition['applicability'] ?? 'review') !== 'food' || $value === null || !is_numeric($value)) {
            $unknown++;
            continue;
        }
        $known += round((float) $value * max(1, (int) ($item['quantity'] ?? 1)), 2);
        $knownItems++;
    }
    return ['known_kcal' => round($known, 2), 'known_items' => $knownItems, 'unknown_items' => $unknown, 'total_items' => count($items)];
}

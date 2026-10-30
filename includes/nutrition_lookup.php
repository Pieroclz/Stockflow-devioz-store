<?php
declare(strict_types=1);

require_once __DIR__ . '/nutrition.php';

function nutrition_parse_open_food_facts(array $payload): array
{
    if ((int) ($payload['status'] ?? 0) !== 1 || !is_array($payload['product'] ?? null)) return [];
    $product = $payload['product'];
    $nutriments = is_array($product['nutriments'] ?? null) ? $product['nutriments'] : [];
    $parsed = nutrition_normalize([
        'applicability' => 'food',
        'energy_kcal_100g' => $nutriments['energy-kcal_100g'] ?? $nutriments['energy-kcal'] ?? null,
        'source_type' => 'open_food_facts',
        'source_ref' => (string) ($product['code'] ?? ''),
        'confidence' => 'reference',
    ]);
    return $parsed['energy_kcal_100g'] === null ? [] : $parsed;
}

function nutrition_can_replace(array $current, array $incoming): bool
{
    return !(($current['source_type'] ?? '') === 'label' && ($current['confidence'] ?? '') === 'verified') && $incoming !== [];
}

function nutrition_lookup_open_food_facts(string $ean, array $config = []): array
{
    if (!preg_match('/^(?:\d{8}|\d{12,14})$/D', $ean)) throw new InvalidArgumentException('EAN invalido.');
    $timeout = max(1, min(5, (int) ($config['timeout'] ?? 5)));
    $url = 'https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($ean) . '?fields=code,product_name,brands,serving_size,nutriments';
    $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $context, 0, 262144);
    if (!is_string($json) || $json === '') return [];
    $payload = json_decode($json, true);
    return is_array($payload) ? nutrition_parse_open_food_facts($payload) : [];
}

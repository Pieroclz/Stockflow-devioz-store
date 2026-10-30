<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/combo_builder.php';

$quote = combo_builder_quote([
    1 => ['stock' => 10, 'cost_price' => 2, 'price' => 5, 'restricted' => 0, 'active' => 1, 'sale_enabled' => 1],
    2 => ['stock' => 5, 'cost_price' => 1, 'price' => 3, 'restricted' => 0, 'active' => 1, 'sale_enabled' => 1],
], [
    ['id' => 1, 'quantity' => 2],
    ['id' => 1, 'quantity' => 1],
    ['id' => 2, 'quantity' => 1],
]);

assert($quote['max_stock'] === 3);
assert($quote['cost'] === 7.0);
assert(count($quote['items']) === 2);

echo "combo_builder_test OK\n";

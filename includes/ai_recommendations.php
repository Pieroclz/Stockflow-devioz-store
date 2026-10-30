<?php

declare(strict_types=1);

require_once __DIR__ . '/master_catalog.php';
/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN
|--------------------------------------------------------------------------
*/

const RECOMMENDATION_MAX_QUANTITY = 99;
const RECOMMENDATION_MAX_TOTAL_QUANTITY = 99;
const RECOMMENDATION_MAX_LINES = 12;

/*
 * Cantidad máxima de productos que se pueden consultar desde BD
 * para alimentar el recomendador.
 */
const RECOMMENDATION_CANDIDATE_LIMIT = 100;

/*
 * Cantidad máxima de alternativas que se mostrarán.
 */
const RECOMMENDATION_MAX_OPTIONS = 12;

/*
|--------------------------------------------------------------------------
| NORMALIZACIÓN DE LA SOLICITUD
|--------------------------------------------------------------------------
*/

function recommendation_request(array $input): array
{
    $budget = is_numeric($input['budget'] ?? null)
        ? max(0.0, min(10000.0, (float)$input['budget']))
        : null;

    $query = mb_substr(
        trim((string)($input['query'] ?? '')),
        0,
        240
    );

    $category = mb_substr(
        trim((string)($input['category'] ?? '')),
        0,
        80
    );

    $limit = max(
        1,
        min(
            RECOMMENDATION_MAX_OPTIONS,
            (int)($input['limit'] ?? 8)
        )
    );

    return [
        'query' => $query,
        'budget' => $budget,
        'category' => $category,
        'limit' => $limit,
    ];
}

/*
|--------------------------------------------------------------------------
| NORMALIZACIÓN DE TEXTO
|--------------------------------------------------------------------------
*/

function ai_normalize(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');

    return strtr($value, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ]);
}

/*
|--------------------------------------------------------------------------
| PALABRAS DE BÚSQUEDA
|--------------------------------------------------------------------------
*/

function recommendation_search_terms(string $query): array
{
    $query = ai_normalize(trim($query));

    if ($query === '') {
        return [];
    }

    /*
     * Eliminamos palabras demasiado comunes.
     */
    $stopWords = [
        'quiero',
        'necesito',
        'busco',
        'dame',
        'mostrar',
        'muestrame',
        'productos',
        'producto',
        'un',
        'una',
        'unos',
        'unas',
        'para',
        'por',
        'con',
        'de',
        'del',
        'la',
        'el',
        'los',
        'las',
        'que',
        'me',
        'mi',
        'y',
        'o',
        'a',
        'en',
    ];

    $words = preg_split('/\s+/', $query);

    if (!is_array($words)) {
        return [];
    }

    $result = [];

    foreach ($words as $word) {
        $word = trim($word);

        if ($word === '') {
            continue;
        }

        if (mb_strlen($word) < 2) {
            continue;
        }

        if (in_array($word, $stopWords, true)) {
            continue;
        }

        $result[] = $word;
    }

    return array_values(array_unique($result));
}

/*
|--------------------------------------------------------------------------
| PRODUCTOS RESTRINGIDOS
|--------------------------------------------------------------------------
*/

function is_age_restricted_product(array $product): bool
{
    $text = ai_normalize(
        implode(' ', [
            (string)($product['name'] ?? ''),
            (string)($product['category'] ?? ''),
            (string)($product['description'] ?? ''),
        ])
    );

    return preg_match(
        '/\b(cerveza(?:s)?|vino(?:s)?|licor(?:es)?|alcohol(?:ica|ico|icas|icos)?|whisk(?:y|ey)|vodka|ron|pisco|tequila|ginebra|gin|brandy|champagne|sidra|espumante|tabaco|cigarro|nicotina|vapeador|cannabis|marihuana)\b|bebida\s+energizante|energy\s+drink/u',
        $text
    ) === 1;
}

/*
|--------------------------------------------------------------------------
| SQL DE VISIBILIDAD
|--------------------------------------------------------------------------
*/

function public_text_visibility_sql(array $expressions): string
{
    $text = "LOWER(CONCAT_WS(' ', " . implode(', ', $expressions) . '))';

    $restricted =
        '(^|[^[:alnum:]])' .
        '(cervezas?|vinos?|licor(es)?|alcohol(ica|ico|icas|icos)?|' .
        'whisk(y|ey)|vodka|ron|pisco|tequila|ginebra|gin|brandy|' .
        'champagne|sidra|espumante|tabaco|cigarro|nicotina|vapeador|' .
        'cannabis|marihuana)' .
        '([^[:alnum:]]|$)|' .
        'bebida[[:space:]]+energizante|' .
        'energy[[:space:]]+drink';

    return $text . " NOT REGEXP '" . $restricted . "'";
}

function public_product_text_visibility_sql(string $alias = 'p'): string
{
    return public_text_visibility_sql([
        "COALESCE($alias.name, '')",
        "COALESCE($alias.category, '')",
        "COALESCE($alias.description, '')",
    ]);
}

function public_combo_text_visibility_sql(string $alias = 'c'): string
{
    return public_text_visibility_sql([
        "COALESCE($alias.name, '')",
        "COALESCE($alias.description, '')",
    ]);
}

/*
|--------------------------------------------------------------------------
| PRODUCTO VENDIBLE
|--------------------------------------------------------------------------
*/

function recommendation_candidate_is_sellable(array $product): bool
{
    return ($product['catalog_scope'] ?? 'master') === 'master'
        && sale_eligible_product($product)
        && !is_age_restricted_product($product);
}

/*
|--------------------------------------------------------------------------
| DINERO
|--------------------------------------------------------------------------
*/

function recommendation_money_cents(mixed $value): int
{
    return is_numeric($value)
        ? max(0, (int)round((float)$value * 100))
        : 0;
}

/*
|--------------------------------------------------------------------------
| CONFIRMACIÓN
|--------------------------------------------------------------------------
*/

/**
 * @return list<array{id:int,qty:int,price_cents:int}>
 */
function recommendation_confirmation_items(mixed $items): array
{
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];

    foreach ($items as $item) {

        if (!is_array($item)) {
            return [];
        }

        $id = $item['id'] ?? null;

        $quantity = $item['qty']
            ?? ($item['quantity'] ?? null);

        $price = $item['price'] ?? null;

        if (
            !is_int($id) ||
            $id < 1 ||
            !is_int($quantity) ||
            $quantity < 1 ||
            $quantity > RECOMMENDATION_MAX_QUANTITY
        ) {
            return [];
        }

        if (
            $price === null &&
            isset($item['unit_cents']) &&
            is_numeric($item['unit_cents'])
        ) {
            $price = (float)$item['unit_cents'] / 100;
        }

        $priceCents = recommendation_money_cents($price);

        if (
            $priceCents < 1 ||
            isset($normalized[$id])
        ) {
            return [];
        }

        $normalized[$id] = [
            'id' => $id,
            'qty' => $quantity,
            'price_cents' => $priceCents,
        ];
    }

    return array_values($normalized);
}

/**
 * @return list<array{id:int,qty:int}>
 */
function recommendation_reconciled_items(mixed $items): array
{
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];

    foreach ($items as $item) {

        if (!is_array($item)) {
            return [];
        }

        $id = $item['id'] ?? null;

        $quantity = $item['quantity']
            ?? ($item['qty'] ?? null);

        $type = (string)($item['type'] ?? 'product');

        if (
            $type !== 'product' ||
            !is_int($id) ||
            $id < 1 ||
            !is_int($quantity) ||
            $quantity < 1 ||
            $quantity > RECOMMENDATION_MAX_QUANTITY ||
            isset($normalized[$id])
        ) {
            return [];
        }

        $normalized[$id] = [
            'id' => $id,
            'qty' => $quantity,
        ];
    }

    return array_values($normalized);
}

function recommendation_confirmation_digest(array $items): string
{
    if (
        !isset($_SESSION['ai_recommendation_confirmation_secret']) ||
        !is_string($_SESSION['ai_recommendation_confirmation_secret'])
    ) {
        $_SESSION['ai_recommendation_confirmation_secret'] =
            bin2hex(random_bytes(32));
    }

    return hash_hmac(
        'sha256',
        (string)json_encode(
            $items,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        $_SESSION['ai_recommendation_confirmation_secret']
    );
}

/*
|--------------------------------------------------------------------------
| PUNTUACIÓN DE PRODUCTOS
|--------------------------------------------------------------------------
|
| Esta es una de las mejoras principales.
|
| El sistema ya no se limita a ordenar por precio.
|
| Evalúa:
| - coincidencia del nombre
| - coincidencia de categoría
| - descripción
| - coincidencia de palabras
| - stock
| - precio
| - producto destacado
|--------------------------------------------------------------------------
*/

function recommendation_product_score(
    array $product,
    array $request
): float {

    $name = ai_normalize((string)($product['name'] ?? ''));
    $category = ai_normalize((string)($product['category'] ?? ''));
    $description = ai_normalize((string)($product['description'] ?? ''));

    $fullText = $name . ' ' . $category . ' ' . $description;

    $query = ai_normalize((string)($request['query'] ?? ''));

    $score = 0.0;

    /*
     * Coincidencia exacta de la búsqueda.
     */
    if ($query !== '') {

        if (str_contains($name, $query)) {
            $score += 100;
        }

        if (str_contains($category, $query)) {
            $score += 60;
        }

        if (str_contains($description, $query)) {
            $score += 30;
        }
    }

    /*
     * Coincidencia palabra por palabra.
     */
    $terms = recommendation_search_terms($query);

    foreach ($terms as $term) {

        if (str_contains($name, $term)) {
            $score += 25;
        }

        if (str_contains($category, $term)) {
            $score += 15;
        }

        if (str_contains($description, $term)) {
            $score += 8;
        }
    }

    /*
     * Categoría solicitada.
     */
    $requestedCategory = ai_normalize(
        (string)($request['category'] ?? '')
    );

    if (
        $requestedCategory !== '' &&
        str_contains($category, $requestedCategory)
    ) {
        $score += 80;
    }

    /*
     * Producto destacado.
     */
    if (
        isset($product['featured']) &&
        (int)$product['featured'] === 1
    ) {
        $score += 10;
    }

    /*
     * Stock.
     *
     * No queremos recomendar primero productos
     * prácticamente agotados.
     */
    $stock = max(0, (int)($product['stock'] ?? 0));

    if ($stock >= 10) {
        $score += 8;
    } elseif ($stock >= 5) {
        $score += 5;
    } elseif ($stock >= 2) {
        $score += 2;
    }

    /*
     * Precio.
     *
     * Un precio menor recibe una pequeña ventaja,
     * pero NO domina toda la recomendación.
     */
    $price = (float)($product['price'] ?? 0);

    if ($price > 0) {
        $score += max(0, 15 - min(15, $price / 20));
    }

    return $score;
}

/*
|--------------------------------------------------------------------------
| RECOMENDACIONES BASADAS EN REGLAS
|--------------------------------------------------------------------------
*/

function rule_based_recommendations(
    array $candidates,
    array $request
): array {

    $budget = $request['budget'] ?? null;

    $budgetCents = $budget === null
        ? null
        : recommendation_money_cents($budget);

    $category = ai_normalize(
        (string)($request['category'] ?? '')
    );

    $limit = max(
        1,
        min(
            RECOMMENDATION_MAX_OPTIONS,
            (int)($request['limit'] ?? 8)
        )
    );

    /*
     * Filtrar productos válidos.
     */
    $rows = array_values(
        array_filter(
            $candidates,
            static function (array $product) use (
                $budgetCents,
                $category
            ): bool {

                $priceCents =
                    recommendation_money_cents(
                        $product['price'] ?? null
                    );

                $productCategory = ai_normalize(
                    (string)($product['category'] ?? '')
                );

                return recommendation_candidate_is_sellable($product)
                    && ($budgetCents === null ||
                        $priceCents <= $budgetCents)
                    && (
                        $category === '' ||
                        str_contains(
                            $productCategory,
                            $category
                        )
                    );
            }
        )
    );

    /*
     * Calcular puntuación.
     */
    foreach ($rows as &$row) {
        $row['_recommendation_score'] =
            recommendation_product_score(
                $row,
                $request
            );
    }

    unset($row);

    /*
     * Ordenar por puntuación.
     */
    usort(
        $rows,
        static function (array $a, array $b): int {

            $scoreCompare =
                ($b['_recommendation_score'] ?? 0)
                <=>
                ($a['_recommendation_score'] ?? 0);

            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return recommendation_money_cents(
                $a['price'] ?? 0
            )
            <=>
            recommendation_money_cents(
                $b['price'] ?? 0
            );
        }
    );

    /*
     * Si NO hay presupuesto:
     * devolver simplemente las mejores opciones.
     */
    if ($budgetCents === null) {

        $result = array_slice(
            $rows,
            0,
            $limit
        );

        foreach ($result as &$item) {
            unset($item['_recommendation_score']);
        }

        unset($item);

        return $result;
    }

    /*
     * Con presupuesto:
     *
     * Primero obtenemos las mejores opciones
     * que entren en el presupuesto.
     */
    $selected = [];
    $remaining = $budgetCents;

    foreach ($rows as $row) {

        $priceCents =
            recommendation_money_cents(
                $row['price'] ?? 0
            );

        if ($priceCents <= $remaining) {

            $selected[] = $row;

            $remaining -= $priceCents;

            if (count($selected) >= $limit) {
                break;
            }
        }
    }

    /*
     * Si no conseguimos suficientes productos,
     * permitimos productos individuales que entren
     * en el presupuesto aunque no se puedan combinar.
     */
    if (count($selected) < $limit) {

        foreach ($rows as $row) {

            $alreadyExists = false;

            foreach ($selected as $selectedProduct) {

                if (
                    (int)$selectedProduct['id'] ===
                    (int)$row['id']
                ) {
                    $alreadyExists = true;
                    break;
                }
            }

            if ($alreadyExists) {
                continue;
            }

            $priceCents =
                recommendation_money_cents(
                    $row['price'] ?? 0
                );

            if ($priceCents <= $budgetCents) {

                $selected[] = $row;

                if (count($selected) >= $limit) {
                    break;
                }
            }
        }
    }

    foreach ($selected as &$item) {
        unset($item['_recommendation_score']);
    }

    unset($item);

    return $selected;
}

/*
|--------------------------------------------------------------------------
| VALIDACIÓN DE RESPUESTAS DEL MODELO
|--------------------------------------------------------------------------
*/

function validate_model_recommendations(
    array $modelOutput,
    array $candidateMap,
    ?float $budget,
    int $limit = 12,
    int $maxTotalQuantity = RECOMMENDATION_MAX_TOTAL_QUANTITY
): array {

    $valid = [];
    $used = [];

    $remaining = $budget === null
        ? null
        : recommendation_money_cents($budget);

    $limit = max(
        1,
        min(RECOMMENDATION_MAX_LINES, $limit)
    );

    $maxTotalQuantity = max(
        1,
        min(100, $maxTotalQuantity)
    );

    $totalQuantity = 0;

    foreach ($modelOutput as $row) {

        if (
            !is_array($row) ||
            !is_int($row['id'] ?? null) ||
            !is_int($row['qty'] ?? null) ||
            $row['id'] < 1 ||
            $row['qty'] < 1
        ) {
            continue;
        }

        $id = $row['id'];

        if (
            !isset($candidateMap[$id]) ||
            !is_array($candidateMap[$id])
        ) {
            continue;
        }

        $product = $candidateMap[$id];

        if (
            !recommendation_candidate_is_sellable($product)
        ) {
            continue;
        }

        if (
            !isset($valid[$id]) &&
            count($valid) >= $limit
        ) {
            continue;
        }

        $priceCents =
            recommendation_money_cents(
                $product['price'] ?? null
            );

        $available = max(
            0,
            (int)$product['stock'] -
            (int)($used[$id] ?? 0)
        );

        if (
            $priceCents < 1 ||
            $available < 1
        ) {
            continue;
        }

        $qty = min(
            $available,
            $row['qty'],
            RECOMMENDATION_MAX_QUANTITY,
            $maxTotalQuantity - $totalQuantity
        );

        if ($remaining !== null) {

            $qty = min(
                $qty,
                intdiv($remaining, $priceCents)
            );
        }

        if ($qty < 1) {
            continue;
        }

        $used[$id] =
            ($used[$id] ?? 0) + $qty;

        $valid[$id] = [
            'id' => $id,
            'qty' => $used[$id],
        ];

        $totalQuantity += $qty;

        if ($remaining !== null) {
            $remaining -=
                $priceCents * $qty;
        }

        if (
            $totalQuantity >=
            $maxTotalQuantity
        ) {
            break;
        }
    }

    return array_values($valid);
}

/*
|--------------------------------------------------------------------------
| OLLAMA
|--------------------------------------------------------------------------
*/

function ollama_recommendations(
    array $candidates,
    array $request,
    array $config
): ?array {

    if (
        !function_exists('curl_init') ||
        ($config['provider'] ?? '') !== 'ollama' ||
        ($config['model'] ?? '') === ''
    ) {
        return null;
    }

    $base = rtrim(
        (string)(
            $config['ollama_url'] ??
            'http://127.0.0.1:11434'
        ),
        '/'
    );

    /*
     * Por seguridad solo permitimos Ollama local.
     */
    if (
        !in_array(
            $base,
            [
                'http://127.0.0.1:11434',
                'http://localhost:11434'
            ],
            true
        )
    ) {
        return null;
    }

    $catalog =
        recommendation_prompt_catalog(
            $candidates
        );

    $body = [
        'model' => $config['model'],
        'stream' => false,
        'format' => 'json',
        'think' => false,
        'options' => [
            'temperature' => 0.2,
            'num_predict' => 700,
            'num_ctx' => 4096,
        ],
        'messages' => [

            [
                'role' => 'system',
                'content' =>
                    'Eres un recomendador de productos. ' .
                    'Solo puedes elegir IDs existentes en el catálogo. ' .
                    'Nunca inventes productos ni IDs. ' .
                    'Excluye productos restringidos por edad. ' .
                    'Respeta el presupuesto y el stock. ' .
                    'Busca variedad cuando sea posible. ' .
                    'Prioriza coincidencia entre la solicitud y el producto. ' .
                    'Devuelve hasta el límite solicitado. ' .
                    'Devuelve exclusivamente JSON con esta estructura: ' .
                    '{"items":[{"id":1,"qty":1}]}'
            ],

            [
                'role' => 'user',
                'content' =>
                    json_encode(
                        [
                            'request' => $request,
                            'catalog' => $catalog,
                        ],
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    ),
            ],
        ],
    ];

    $raw = '';

    $ch = curl_init(
        $base . '/api/chat'
    );

    curl_setopt_array(
        $ch,
        [
            CURLOPT_POST => true,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],

            CURLOPT_POSTFIELDS =>
                json_encode(
                    $body,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            CURLOPT_CONNECTTIMEOUT => 4,

            CURLOPT_TIMEOUT => 15,

            CURLOPT_FOLLOWLOCATION => false,

            CURLOPT_WRITEFUNCTION =>
                static function (
                    $ch,
                    $chunk
                ) use (&$raw) {

                    if (
                        strlen($raw) +
                        strlen($chunk) >
                        131072
                    ) {
                        return 0;
                    }

                    $raw .= $chunk;

                    return strlen($chunk);
                },
        ]
    );

    $ok = curl_exec($ch);

    $status =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    if (
        $ok === false ||
        $status !== 200
    ) {
        return null;
    }

    $outer =
        json_decode(
            $raw,
            true
        );

    $answer =
        json_decode(
            (string)(
                $outer['message']['content'] ?? ''
            ),
            true
        );

    if (
        !is_array($answer) ||
        !is_array($answer['items'] ?? null)
    ) {
        return null;
    }

    if (
        count($answer['items']) > 60
    ) {
        return null;
    }

    if (
        $answer['items'] !== [] &&
        array_keys($answer['items']) !==
        range(
            0,
            count($answer['items']) - 1
        )
    ) {
        return null;
    }

    $map = [];

    foreach ($candidates as $candidate) {
        $map[(int)$candidate['id']] =
            $candidate;
    }

    $validated =
        validate_model_recommendations(
            $answer['items'],
            $map,
            $request['budget'],
            (int)($request['limit'] ?? 8)
        );

    /*
     * Si Ollama devolvió cosas inválidas,
     * usamos el sistema de reglas como respaldo.
     */
    if (
        $answer['items'] !== [] &&
        $validated === []
    ) {
        return null;
    }

    return $validated;
}

/*
|--------------------------------------------------------------------------
| CATÁLOGO PARA OLLAMA
|--------------------------------------------------------------------------
*/

function recommendation_prompt_catalog(
    array $candidates
): array {

    return array_map(
        static function (array $product): array {

            return [
                'id' =>
                    (int)$product['id'],

                'name' =>
                    (string)$product['name'],

                'category' =>
                    (string)$product['category'],

                'description' =>
                    mb_substr(
                        (string)(
                            $product['description'] ?? ''
                        ),
                        0,
                        300
                    ),

                'price' =>
                    (float)$product['price'],

                'stock' =>
                    (int)$product['stock'],
            ];
        },
        $candidates
    );
}

/*
|--------------------------------------------------------------------------
| OBTENER CANDIDATOS
|--------------------------------------------------------------------------
*/

function recommendation_candidates(
    PDO $pdo,
    array $request
): array {

    $where = [
        master_product_visibility_sql('p'),
        public_product_text_visibility_sql('p'),
        'p.active=1',
        'p.sale_enabled=1',
        'p.stock>0',
    ];

    $params = [];

    /*
     * Categoría.
     */
    if (
        ($request['category'] ?? '') !== ''
    ) {

        $where[] =
            'p.category=:category';

        $params['category'] =
            $request['category'];
    }

    /*
     * Presupuesto.
     *
     * IMPORTANTE:
     * No filtramos obligatoriamente por presupuesto.
     *
     * Esto permite tener alternativas cercanas
     * cuando no hay suficientes productos.
     */
    $sql =
        'SELECT ' .
        'p.id,' .
        'p.code,' .
        'p.name,' .
        'p.category,' .
        'p.description,' .
        'p.price,' .
        'p.stock,' .
        'p.image_url,' .
        'p.entrega_inmediata,' .
        'p.catalog_scope,' .
        'p.active,' .
        'p.sale_enabled,' .
        'p.restricted,' .
        'p.featured ' .
        'FROM products p ' .
        'WHERE ' .
        implode(
            ' AND ',
            $where
        ) .
        ' ORDER BY ' .
        'p.featured DESC,' .
        'p.stock DESC,' .
        'p.price ASC,' .
        'p.id ASC ' .
        'LIMIT ' .
        RECOMMENDATION_CANDIDATE_LIMIT;

    $statement =
        $pdo->prepare($sql);

    $statement->execute($params);

    $products =
        $statement->fetchAll();

    return array_values(
        array_filter(
            $products,
            static function (
                array $product
            ): bool {

                return
                    recommendation_candidate_is_sellable(
                        $product
                    );
            }
        )
    );
}

/*
|--------------------------------------------------------------------------
| CANDIDATOS POR IDS
|--------------------------------------------------------------------------
*/

/**
 * @param list<int> $ids
 */
function recommendation_candidates_for_ids(
    PDO $pdo,
    array $request,
    array $ids
): array {

    $ids = array_values(
        array_unique(
            array_filter(
                $ids,
                static fn($id): bool =>
                    is_int($id) &&
                    $id > 0
            )
        )
    );

    if ($ids === []) {
        return [];
    }

    $ids = array_slice(
        $ids,
        0,
        RECOMMENDATION_MAX_LINES
    );

    $where = [
        master_product_visibility_sql('p'),
        public_product_text_visibility_sql('p'),
        'p.active=1',
        'p.sale_enabled=1',
        'p.stock>0',
    ];

    $params = [];
    $placeholders = [];

    foreach (
        $ids as $index => $id
    ) {

        $key = 'id' . $index;

        $placeholders[] =
            ':' . $key;

        $params[$key] = $id;
    }

    $where[] =
        'p.id IN (' .
        implode(
            ',',
            $placeholders
        ) .
        ')';

    if (
        ($request['category'] ?? '') !== ''
    ) {

        $where[] =
            'p.category=:category';

        $params['category'] =
            $request['category'];
    }

    $sql =
        'SELECT ' .
        'p.id,' .
        'p.code,' .
        'p.name,' .
        'p.category,' .
        'p.description,' .
        'p.price,' .
        'p.stock,' .
        'p.image_url,' .
        'p.entrega_inmediata,' .
        'p.catalog_scope,' .
        'p.active,' .
        'p.sale_enabled,' .
        'p.restricted,' .
        'p.featured ' .
        'FROM products p ' .
        'WHERE ' .
        implode(
            ' AND ',
            $where
        ) .
        ' ORDER BY ' .
        'p.featured DESC,' .
        'p.price ASC,' .
        'p.id ASC';

    $statement =
        $pdo->prepare($sql);

    $statement->execute($params);

    return array_values(
        array_filter(
            $statement->fetchAll(),
            static function (
                array $product
            ): bool {

                return
                    recommendation_candidate_is_sellable(
                        $product
                    );
            }
        )
    );
}

/*
|--------------------------------------------------------------------------
| PAYLOAD DE RECOMENDACIÓN
|--------------------------------------------------------------------------
*/

function recommendation_payload(
    array $candidates,
    array $selections,
    ?float $budget = null,
    int $limit = RECOMMENDATION_MAX_OPTIONS
): array {

    $map = [];

    foreach ($candidates as $candidate) {

        $map[
            (int)$candidate['id']
        ] = $candidate;
    }

    $selections =
        validate_model_recommendations(
            $selections,
            $map,
            $budget,
            $limit
        );

    $items = [];
    $total = 0.0;

    foreach ($selections as $selection) {

        if (
            !is_array($selection) ||
            !is_int($selection['id'] ?? null) ||
            !is_int($selection['qty'] ?? null)
        ) {
            continue;
        }

        $id =
            $selection['id'];

        $qty =
            $selection['qty'];

        if (
            !isset($map[$id]) ||
            !recommendation_candidate_is_sellable(
                $map[$id]
            ) ||
            !sale_eligible_product(
                $map[$id],
                $qty
            )
        ) {
            continue;
        }

        $product =
            $map[$id];

        $subtotal =
            round(
                (float)$product['price'] *
                $qty,
                2
            );

        $total += $subtotal;

        $items[] = [
            'id' =>
                $id,

            'code' =>
                $product['code'],

            'name' =>
                $product['name'],

            'category' =>
                $product['category'],

            'description' =>
                $product['description'] ?? '',

            'price' =>
                (float)$product['price'],

            'stock' =>
                (int)$product['stock'],

            'image_url' =>
                $product['image_url'] ?? '',

            'entrega_inmediata' =>
                $product['entrega_inmediata'] ?? 0,

            'quantity' =>
                $qty,

            'subtotal' =>
                $subtotal,
        ];
    }

    return [
        'items' =>
            $items,

        'total' =>
            round(
                $total,
                2
            ),

        'count' =>
            count($items),

        'budget' =>
            $budget,

        'remaining_budget' =>
            $budget === null
                ? null
                : round(
                    max(
                        0,
                        $budget - $total
                    ),
                    2
                ),
    ];
}

/*
|--------------------------------------------------------------------------
| SUGERENCIAS ADICIONALES
|--------------------------------------------------------------------------
|
| Esta función permite devolver productos alternativos
| aunque no hayan sido seleccionados inicialmente.
|--------------------------------------------------------------------------
*/

function recommendation_alternatives(
    array $candidates,
    array $selectedIds,
    array $request,
    int $limit = 6
): array {

    $selectedMap = [];

    foreach ($selectedIds as $id) {
        $selectedMap[(int)$id] = true;
    }

    $alternatives = [];

    foreach ($candidates as $product) {

        $id = (int)($product['id'] ?? 0);

        if ($id < 1 || isset($selectedMap[$id])) {
            continue;
        }

        if (
            !recommendation_candidate_is_sellable(
                $product
            )
        ) {
            continue;
        }

        $price =
            (float)($product['price'] ?? 0);

        /*
         * Si hay presupuesto, damos prioridad
         * a productos que entren.
         */
        if (
            $request['budget'] !== null &&
            $price > (float)$request['budget']
        ) {
            continue;
        }

        $product['_recommendation_score'] =
            recommendation_product_score(
                $product,
                $request
            );

        $alternatives[] = $product;
    }

    usort(
        $alternatives,
        static function (
            array $a,
            array $b
        ): int {

            return
                ($b['_recommendation_score'] ?? 0)
                <=>
                ($a['_recommendation_score'] ?? 0);
        }
    );

    $alternatives =
        array_slice(
            $alternatives,
            0,
            max(
                1,
                min(12, $limit)
            )
        );

    foreach ($alternatives as &$item) {
        unset(
            $item['_recommendation_score']
        );
    }

    unset($item);

    return $alternatives;
}

/*
|--------------------------------------------------------------------------
| SUGERENCIAS POR PRECIO
|--------------------------------------------------------------------------
*/

function recommendation_price_alternatives(
    array $candidates,
    ?float $budget,
    int $limit = 4
): array {

    if ($budget === null) {
        return [];
    }

    $budgetCents =
        recommendation_money_cents(
            $budget
        );

    $result = [];

    foreach ($candidates as $product) {

        if (
            !recommendation_candidate_is_sellable(
                $product
            )
        ) {
            continue;
        }

        $priceCents =
            recommendation_money_cents(
                $product['price'] ?? 0
            );

        if ($priceCents < 1) {
            continue;
        }

        /*
         * Buscamos productos que estén
         * relativamente cerca del presupuesto.
         */
        $difference =
            abs(
                $budgetCents -
                $priceCents
            );

        $product['_price_difference'] =
            $difference;

        $result[] = $product;
    }

    usort(
        $result,
        static function (
            array $a,
            array $b
        ): int {

            return
                ($a['_price_difference'] ?? PHP_INT_MAX)
                <=>
                ($b['_price_difference'] ?? PHP_INT_MAX);
        }
    );

    $result =
        array_slice(
            $result,
            0,
            max(
                1,
                min(8, $limit)
            )
        );

    foreach ($result as &$item) {
        unset($item['_price_difference']);
    }

    unset($item);

    return $result;
}

/*
|--------------------------------------------------------------------------
| CREAR CONFIRMACIÓN
|--------------------------------------------------------------------------
*/

function recommendation_confirmation_create(
    array $items,
    array $request = []
): ?string {

    $canonical =
        recommendation_confirmation_items(
            $items
        );

    if (
        $canonical === [] ||
        count($canonical) >
        RECOMMENDATION_MAX_LINES
    ) {
        return null;
    }

    $now = time();

    $stored =
        $_SESSION['ai_recommendation_confirmations']
        ?? [];

    if (!is_array($stored)) {
        $stored = [];
    }

    $stored =
        array_filter(
            $stored,
            static function (
                $entry
            ): bool {

                return
                    is_array($entry) &&
                    (int)(
                        $entry['expires'] ?? 0
                    ) >= time();
            }
        );

    while (
        count($stored) >= 10
    ) {
        array_shift($stored);
    }

    $token =
        bin2hex(
            random_bytes(24)
        );

    $stored[$token] = [
        'expires' =>
            $now + 600,

        'digest' =>
            recommendation_confirmation_digest(
                $canonical
            ),

        'items' =>
            $canonical,

        'request' => [
            'budget' =>
                isset($request['budget']) &&
                is_numeric($request['budget'])
                    ? (float)$request['budget']
                    : null,

            'category' =>
                mb_substr(
                    trim(
                        (string)(
                            $request['category']
                            ?? ''
                        )
                    ),
                    0,
                    80
                ),

            'line_limit' =>
                max(
                    1,
                    min(
                        RECOMMENDATION_MAX_LINES,
                        (int)(
                            $request['line_limit']
                            ??
                            $request['limit']
                            ??
                            count($canonical)
                        )
                    )
                ),

            'quantity_limit' =>
                max(
                    1,
                    min(
                        RECOMMENDATION_MAX_QUANTITY,
                        (int)(
                            $request['quantity_limit']
                            ??
                            RECOMMENDATION_MAX_QUANTITY
                        )
                    )
                ),

            'total_quantity_limit' =>
                max(
                    1,
                    min(
                        100,
                        (int)(
                            $request['total_quantity_limit']
                            ??
                            RECOMMENDATION_MAX_TOTAL_QUANTITY
                        )
                    )
                ),
        ],
    ];

    $_SESSION[
        'ai_recommendation_confirmations'
    ] = $stored;

    return $token;
}

/*
|--------------------------------------------------------------------------
| BUSCAR CONFIRMACIÓN
|--------------------------------------------------------------------------
*/

function recommendation_confirmation_record(
    string $token
): ?array {

    if (
        !preg_match(
            '/^[a-f0-9]{48}$/D',
            $token
        )
    ) {
        return null;
    }

    $stored =
        $_SESSION[
            'ai_recommendation_confirmations'
        ] ?? [];

    if (
        !is_array($stored) ||
        !isset($stored[$token]) ||
        !is_array($stored[$token])
    ) {
        return null;
    }

    return
        (int)(
            $stored[$token]['expires'] ?? 0
        ) >= time()
            ? $stored[$token]
            : null;
}

/*
|--------------------------------------------------------------------------
| COMPROBAR CONFIRMACIÓN
|--------------------------------------------------------------------------
*/

function recommendation_confirmation_matches(
    string $token,
    mixed $items
): bool {

    $record =
        recommendation_confirmation_record(
            $token
        );

    $canonical =
        recommendation_confirmation_items(
            $items
        );

    return
        $record !== null &&
        $canonical !== [] &&
        hash_equals(
            (string)(
                $record['digest'] ?? ''
            ),
            recommendation_confirmation_digest(
                $canonical
            )
        );
}

/*
|--------------------------------------------------------------------------
| CONSUMIR CONFIRMACIÓN
|--------------------------------------------------------------------------
*/

function recommendation_confirmation_consume(
    string $token,
    ?array $items = null
): bool {

    if (
        !is_array($items) ||
        !recommendation_confirmation_matches(
            $token,
            $items
        )
    ) {
        return false;
    }

    $stored =
        $_SESSION[
            'ai_recommendation_confirmations'
        ] ?? [];

    if (
        !is_array($stored) ||
        !isset($stored[$token])
    ) {
        return false;
    }

    unset(
        $stored[$token]
    );

    $_SESSION[
        'ai_recommendation_confirmations'
    ] = $stored;

    return true;
}

/*
|--------------------------------------------------------------------------
| FUNCIÓN PRINCIPAL DEL RECOMENDADOR
|--------------------------------------------------------------------------
|
| Esta función reúne todo:
|
| 1. Obtiene productos.
| 2. Intenta usar Ollama.
| 3. Si Ollama falla, usa reglas.
| 4. Genera alternativas.
| 5. Genera opciones cercanas al presupuesto.
|--------------------------------------------------------------------------
*/

function recommendation_generate(
    PDO $pdo,
    array $input,
    array $config = []
): array {

    $request =
        recommendation_request(
            $input
        );

    $candidates =
        recommendation_candidates(
            $pdo,
            $request
        );

    /*
     * Si no hay candidatos.
     */
    if ($candidates === []) {

        return [
            'success' => false,
            'message' =>
                'No encontramos productos disponibles que coincidan con tu búsqueda.',
            'items' => [],
            'alternatives' => [],
            'price_alternatives' => [],
            'total' => 0,
            'budget' => $request['budget'],
        ];
    }

    /*
     * Intentar primero IA.
     */
    $selections =
        ollama_recommendations(
            $candidates,
            $request,
            $config
        );

    /*
     * Si IA no responde, usar reglas.
     */
    if ($selections === null) {

        $ruleProducts =
            rule_based_recommendations(
                $candidates,
                $request
            );

        $selections = [];

        foreach ($ruleProducts as $product) {

            $selections[] = [
                'id' =>
                    (int)$product['id'],

                'qty' => 1,
            ];
        }
    }

    /*
     * Crear payload final.
     */
    $payload =
        recommendation_payload(
            $candidates,
            $selections,
            $request['budget'],
            $request['limit']
        );

    /*
     * IDs seleccionados.
     */
    $selectedIds = [];

    foreach ($payload['items'] as $item) {

        $selectedIds[] =
            (int)$item['id'];
    }

    /*
     * Alternativas.
     */
    $alternatives =
        recommendation_alternatives(
            $candidates,
            $selectedIds,
            $request,
            6
        );

    /*
     * Alternativas por precio.
     */
    $priceAlternatives =
        recommendation_price_alternatives(
            $candidates,
            $request['budget'],
            4
        );

    return [
        'success' => true,

        'message' =>
            count($payload['items']) > 0
                ? 'Encontramos opciones que pueden interesarte.'
                : 'No encontramos una combinación exacta, pero tenemos alternativas.',

        'items' =>
            $payload['items'],

        'alternatives' =>
            $alternatives,

        'price_alternatives' =>
            $priceAlternatives,

        'total' =>
            $payload['total'],

        'count' =>
            $payload['count'],

        'budget' =>
            $payload['budget'],

        'remaining_budget' =>
            $payload['remaining_budget'],

        'request' =>
            $request,
    ];
}
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $forwardedProtocol = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $secureRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProtocol === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Keep rider authentication isolated from admin/store sessions.
    // Separate browser tabs that open the storefront or dashboard no longer
    // overwrite the rider form CSRF token or rider login session.
    $isDriverPortal = preg_match('~(?:^|/)motorizado(?:/|$)~', (string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 1;
    session_name($isDriverPortal ? 'DEVIOZDRIVERSESSID' : 'DEVIOZSESSID');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secureRequest,
        'path' => ($isDriverPortal ? (BASE_URL !== '' ? BASE_URL : '') . '/motorizado/' : (BASE_URL !== '' ? BASE_URL . '/' : '/')),
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float|string $amount): string
{
    return 'S/ ' . number_format((float) $amount, 2, '.', ',');
}

function absolute_url(string $path = ''): string
{
    $configured = rtrim(env_value('APP_BASE_URL'), '/');
    if ($configured !== '') return $configured . ($path !== '' ? '/' . ltrim($path, '/') : '');
    $forwardedProtocol = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProtocol === 'https') ? 'https' : 'http';
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    return $scheme . '://' . $host . url($path);
}

function image_src(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) return $path;
    if (str_starts_with($path, 'assets/uploads/')) {
        $absolutePath = dirname(__DIR__) . '/' . ltrim($path, '/');
        if (!is_file($absolutePath)) return url('assets/img/product-placeholder.svg');
    }
    return url($path);
}

function upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La imagen supera el tamaño permitido por el servidor.',
        UPLOAD_ERR_PARTIAL => 'La imagen se cargó de forma incompleta. Inténtalo nuevamente.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene disponible una carpeta temporal para recibir imágenes.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir la imagen temporal.',
        UPLOAD_ERR_EXTENSION => 'Una extensión del servidor detuvo la carga de la imagen.',
        default => 'No se pudo cargar la imagen. Inténtalo nuevamente.',
    };
}

function store_uploaded_image_in_database(string $temporaryPath, string $mime, string $originalName): string
{
    $imageData = file_get_contents($temporaryPath);
    if ($imageData === false || $imageData === '') {
        throw new RuntimeException('No se pudo leer la imagen recibida.');
    }

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS uploaded_media (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                mime_type VARCHAR(30) NOT NULL,
                original_name VARCHAR(255) NULL,
                file_size INT UNSIGNED NOT NULL,
                image_data MEDIUMBLOB NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB'
        );
        $statement = db()->prepare(
            'INSERT INTO uploaded_media (mime_type, original_name, file_size, image_data)
             VALUES (:mime_type, :original_name, :file_size, :image_data)'
        );
        $statement->bindValue(':mime_type', $mime);
        $statement->bindValue(':original_name', mb_substr($originalName, 0, 255));
        $statement->bindValue(':file_size', strlen($imageData), PDO::PARAM_INT);
        $statement->bindValue(':image_data', $imageData, PDO::PARAM_LOB);
        $statement->execute();
    } catch (PDOException) {
        throw new RuntimeException('No se pudo almacenar la imagen. Importa database/stockflow.sql y vuelve a intentarlo.');
    }

    return 'media.php?id=' . (int) db()->lastInsertId();
}

function store_uploaded_image(array $file, string $folder = 'products'): string
{
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($uploadError));
    }

    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('La imagen no puede superar los 5 MB.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_file($temporaryPath)) {
        throw new RuntimeException('No se encontró la imagen temporal recibida.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('La imagen debe ser JPG, PNG o WEBP.');
    }
    $dimensions = @getimagesize($temporaryPath);
    if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
        throw new RuntimeException('El archivo no contiene una imagen válida.');
    }
    if ((int) $dimensions[0] * (int) $dimensions[1] > 24000000) {
        throw new RuntimeException('La imagen tiene una resolución demasiado grande. Usa un máximo aproximado de 24 megapíxeles.');
    }

    $safeFolder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'products';
    $relativeDirectory = 'assets/uploads/' . $safeFolder;
    $absoluteDirectory = dirname(__DIR__) . '/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory)) {
        @mkdir($absoluteDirectory, 0755, true);
    }
    if (is_dir($absoluteDirectory) && !is_writable($absoluteDirectory)) {
        @chmod($absoluteDirectory, 0755);
        clearstatcache(true, $absoluteDirectory);
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (is_dir($absoluteDirectory) && is_writable($absoluteDirectory)) {
        $destination = $absoluteDirectory . '/' . $filename;
        if (@move_uploaded_file($temporaryPath, $destination)) {
            @chmod($destination, 0644);
            return $relativeDirectory . '/' . $filename;
        }
    }

    // Respaldo para XAMPP/macOS: si Apache no puede escribir en la carpeta,
    // guarda el archivo como BLOB en MySQL para que la galería no falle.
    return store_uploaded_image_in_database(
        $temporaryPath,
        $mime,
        (string) ($file['name'] ?? $filename)
    );
}

function upload_image(string $field, string $folder = 'products'): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return store_uploaded_image($file, $folder);
}

function upload_images(string $field, string $folder = 'products', int $maximum = 8): array
{
    $files = $_FILES[$field] ?? null;
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $files['size'][$index] ?? 0,
        ];
    }

    if (count($normalized) > $maximum) {
        throw new RuntimeException('Puedes cargar como máximo ' . $maximum . ' imágenes a la vez.');
    }

    return array_map(
        static fn (array $file): string => store_uploaded_image($file, $folder),
        $normalized
    );
}

function valid_image_reference(string $value): bool
{
    if ($value === '') return true;
    if (preg_match('#^assets/uploads/[a-z0-9_-]+/[a-f0-9]{24}\.(jpg|png|webp)$#i', $value)) return true;
    if (preg_match('#^media\.php\?id=[1-9][0-9]*$#', $value)) return true;
    return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1;
}

function attach_product_images(array $products): array
{
    if (!$products) return [];

    $ids = array_values(array_unique(array_filter(array_map(
        static fn (array $product): int => (int) ($product['id'] ?? 0),
        $products
    ))));
    $imageMap = [];

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $statement = db()->prepare(
                'SELECT product_id, image_url FROM product_images
                 WHERE product_id IN (' . $placeholders . ')
                 ORDER BY product_id, sort_order, id'
            );
            $statement->execute($ids);
            foreach ($statement->fetchAll() as $image) {
                $imageMap[(int) $image['product_id']][] = (string) $image['image_url'];
            }
        } catch (PDOException) {
            $imageMap = [];
        }
    }

    foreach ($products as &$product) {
        $images = [];
        $mainImage = trim((string) ($product['image_url'] ?? ''));
        if ($mainImage !== '') $images[] = $mainImage;
        foreach ($imageMap[(int) ($product['id'] ?? 0)] ?? [] as $image) {
            if ($image !== '' && !in_array($image, $images, true)) $images[] = $image;
        }
        $product['images'] = array_slice($images, 0, 8);
    }
    unset($product);

    return $products;
}

function ensure_store_settings_table(): void
{
    static $ensured = false;
    if ($ensured) return;
    db()->exec(
        'CREATE TABLE IF NOT EXISTS store_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
    $ensured = true;
}

function store_setting(string $key, string $default = ''): string
{
    try {
        ensure_store_settings_table();
        $statement = db()->prepare('SELECT setting_value FROM store_settings WHERE setting_key = ? LIMIT 1');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (PDOException) {
        return $default;
    }
}

function save_store_setting(string $key, string $value): void
{
    ensure_store_settings_table();
    $statement = db()->prepare(
        'INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([$key, $value]);
}

function yape_settings(): array
{
    $defaults = ['yape_enabled' => '0', 'yape_phone' => '', 'yape_owner' => '', 'yape_qr' => '', 'order_whatsapp' => ''];
    try {
        ensure_store_settings_table();
        $statement = db()->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key IN ('yape_enabled','yape_phone','yape_owner','yape_qr','order_whatsapp')");
        foreach ($statement->fetchAll() as $setting) $defaults[(string) $setting['setting_key']] = (string) $setting['setting_value'];
    } catch (PDOException) {
        // Conserva valores seguros si la tabla todavía no fue importada.
    }
    return ['enabled' => $defaults['yape_enabled'] === '1', 'phone' => $defaults['yape_phone'], 'owner' => $defaults['yape_owner'], 'qr' => $defaults['yape_qr'], 'whatsapp' => $defaults['order_whatsapp']];
}

function plin_settings(): array
{
    $defaults = ['plin_enabled' => '0', 'plin_phone' => '', 'plin_owner' => '', 'plin_qr' => ''];
    try {
        ensure_store_settings_table();
        $statement = db()->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key IN ('plin_enabled','plin_phone','plin_owner','plin_qr')");
        foreach ($statement->fetchAll() as $setting) $defaults[(string) $setting['setting_key']] = (string) $setting['setting_value'];
    } catch (PDOException) {
        // Plin queda oculto si la configuracion aun no existe.
    }
    return ['enabled' => $defaults['plin_enabled'] === '1', 'phone' => $defaults['plin_phone'], 'owner' => $defaults['plin_owner'], 'qr' => $defaults['plin_qr']];
}

function payment_settings(): array
{
    return ['yape' => yape_settings(), 'plin' => plin_settings()];
}

function payment_method_available(string $method, array $settings): bool
{
    if ($method === 'efectivo') return true;
    if ($method === 'yape' || $method === 'plin') {
        $config = $settings[$method] ?? [];
        return (bool) ($config['enabled'] ?? false)
            && (trim((string) ($config['phone'] ?? '')) !== '' || trim((string) ($config['qr'] ?? '')) !== '');
    }
    if ($method === 'tarjeta') {
        if (!function_exists('culqi_config')) {
            $culqiFile = __DIR__ . '/culqi.php';
            if (is_file($culqiFile)) require_once $culqiFile;
        }
        return function_exists('culqi_config') && (bool) (culqi_config()['enabled'] ?? false);
    }
    return false;
}

function telegram_settings(): array
{
    $defaults = ['telegram_enabled' => '0', 'telegram_bot_token' => '', 'telegram_chat_id' => ''];
    try {
        ensure_store_settings_table();
        $statement = db()->query(
            "SELECT setting_key, setting_value FROM store_settings
             WHERE setting_key IN ('telegram_enabled','telegram_bot_token','telegram_chat_id')"
        );
        foreach ($statement->fetchAll() as $setting) {
            $defaults[(string) $setting['setting_key']] = (string) $setting['setting_value'];
        }
    } catch (PDOException) {
        // Telegram permanece desactivado si la configuración aún no existe.
    }

    return [
        'enabled' => $defaults['telegram_enabled'] === '1',
        'bot_token' => $defaults['telegram_bot_token'],
        'chat_id' => $defaults['telegram_chat_id'],
    ];
}

/**
 * Envía un mensaje mediante Telegram Bot API. Una falla de Telegram nunca debe
 * cancelar una venta; el llamador puede registrar el error o mostrarlo en una prueba.
 *
 * @return array{success: bool, message: string}
 */
function send_telegram_message(string $message): array
{
    $settings = telegram_settings();
    if (!$settings['enabled']) {
        return ['success' => false, 'message' => 'Las notificaciones de Telegram están desactivadas.'];
    }
    if ($settings['bot_token'] === '' || $settings['chat_id'] === '') {
        return ['success' => false, 'message' => 'Falta configurar el token del bot o el Chat ID.'];
    }

    $endpoint = 'https://api.telegram.org/bot' . $settings['bot_token'] . '/sendMessage';
    $payload = http_build_query([
        'chat_id' => $settings['chat_id'],
        'text' => mb_substr($message, 0, 4000),
        'disable_web_page_preview' => 'true',
    ]);
    $response = false;
    $transportError = '';

    if (function_exists('curl_init')) {
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($curl);
        if ($response === false) $transportError = (string) curl_error($curl);
        curl_close($curl);
    } elseif ((bool) ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 4,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($endpoint, false, $context);
    } else {
        $transportError = 'El servidor no tiene cURL ni allow_url_fopen habilitado.';
    }

    if ($response === false) {
        return ['success' => false, 'message' => $transportError ?: 'Telegram no respondió.'];
    }
    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        $description = is_array($decoded) ? trim((string) ($decoded['description'] ?? '')) : '';
        return ['success' => false, 'message' => $description ?: 'Telegram rechazó la solicitud.'];
    }

    return ['success' => true, 'message' => 'Notificación enviada correctamente.'];
}

function send_telegram_order_notification(
    string $orderCode,
    array $items,
    float $total,
    string $paymentMethod,
    string $dispatchType,
    ?float $paysWith = null,
    float $change = 0.0
): array {
    $lines = [];
    foreach (array_slice($items, 0, 20) as $item) {
        $lines[] = '• ' . (int) ($item['quantity'] ?? 0) . '× ' . (string) ($item['name'] ?? 'Producto')
            . ' — ' . money((float) ($item['subtotal'] ?? 0));
    }
    $paymentLabel = payment_label($paymentMethod) . (payment_requires_review($paymentMethod) ? ' (pendiente de revisión)' : ' (aprobado)');
    $dispatchLabel = $dispatchType === 'entrega_rapida' ? 'Entrega rápida' : 'Requiere preparación';
    $message = "🛒 NUEVO PEDIDO\n"
        . "Código: {$orderCode}\n"
        . "Pago: {$paymentLabel}\n"
        . "Despacho: {$dispatchLabel}\n\n"
        . implode("\n", $lines)
        . "\n\nTotal: " . money($total);
    if ($paymentMethod === 'efectivo' && $paysWith !== null) {
        $message .= "\nPaga con: " . money($paysWith) . "\nVuelto: " . money($change);
    }
    $message .= "\n\nRevisar: " . absolute_url('admin/pedidos_yape.php?q=' . rawurlencode($orderCode));

    return send_telegram_message($message);
}

function receipt_number(array $order): string
{
    $date = !empty($order['created_at']) ? date('ymd', strtotime((string) $order['created_at'])) : date('ymd');
    return 'REC-' . $date . '-' . str_pad((string) ((int) ($order['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
}

function receipt_secret(): string
{
    $secret = store_setting('receipt_signing_secret');
    if (strlen($secret) >= 32) return $secret;
    $secret = bin2hex(random_bytes(32));
    save_store_setting('receipt_signing_secret', $secret);
    return $secret;
}

function receipt_access_token(int $orderId): string
{
    return hash_hmac('sha256', 'stockflow-receipt-' . $orderId, receipt_secret());
}

function receipt_url(int $orderId): string
{
    return absolute_url('recibo.php?id=' . $orderId . '&token=' . receipt_access_token($orderId));
}

function send_telegram_delivered_notification(int $orderId): array
{
    $orderStatement = db()->prepare('SELECT * FROM yape_orders WHERE id = ? LIMIT 1');
    $orderStatement->execute([$orderId]);
    $order = $orderStatement->fetch();
    if (!$order || (string) ($order['fulfillment_status'] ?? '') !== 'entregado') {
        return ['success' => false, 'message' => 'El pedido todavía no está entregado.'];
    }
    $itemsStatement = db()->prepare('SELECT item_name, quantity, subtotal FROM yape_order_items WHERE order_id = ? ORDER BY id');
    $itemsStatement->execute([$orderId]);
    $lines = [];
    foreach ($itemsStatement->fetchAll() as $item) {
        $lines[] = '• ' . (int) $item['quantity'] . '× ' . (string) $item['item_name'] . ' — ' . money($item['subtotal']);
    }
    $message = "✅ PEDIDO ENTREGADO\n"
        . 'Recibo: ' . receipt_number($order) . "\n"
        . 'Pedido: ' . (string) $order['order_code'] . "\n"
        . 'Método: ' . payment_label((string) ($order['metodo_pago'] ?? 'yape')) . "\n\n"
        . implode("\n", $lines) . "\n\n"
        . 'Total: ' . money($order['expected_amount']) . "\n"
        . 'Ver recibo digital: ' . receipt_url($orderId);
    return send_telegram_message($message);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('La sesión del formulario expiró. Regresa a la página e inténtalo nuevamente.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function is_admin(): bool
{
    return isset($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!is_admin()) {
        flash('warning', 'Inicia sesión para acceder al panel administrativo.');
        redirect('login.php');
    }
    $role = (string)($_SESSION['admin_role'] ?? 'admin');
    if ($role === 'admin') return;
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = [
        'inventario' => ['index.php','productos.php','todos_productos.php','producto_unificado_detalle.php','producto_unificado_guardar.php','exportar_productos.php','producto_form.php','producto_guardar.php','producto_eliminar.php','categorias.php','combos.php','movimientos.php','precios.php','catalogo_externo.php','fuentes_compras.php','admin_product_search.php'],
        'caja' => ['index.php','pagos.php','pedidos_yape.php'],
        'atencion' => ['index.php','pedidos_yape.php','clientes.php'],
    ];
    if (!in_array($page, $allowed[$role] ?? [], true)) {
        flash('danger', 'Tu rol no tiene permiso para abrir ese módulo.');
        redirect('admin/index.php');
    }
}

function is_customer(): bool
{
    return isset($_SESSION['customer_id']) && (int) $_SESSION['customer_id'] > 0;
}

function customer_id(): int
{
    return is_customer() ? (int) $_SESSION['customer_id'] : 0;
}

function require_customer(): void
{
    if (!is_customer()) {
        flash('warning', 'Inicia sesión para acceder a tu cuenta.');
        redirect('mi_cuenta.php');
    }
}

function customer_profile(): ?array
{
    if (!is_customer()) return null;
    $statement = db()->prepare('SELECT id, name, email, phone FROM customers WHERE id = ? AND active = 1 LIMIT 1');
    $statement->execute([customer_id()]);
    $profile = $statement->fetch();
    if (!$profile) {
        unset($_SESSION['customer_id'], $_SESSION['customer_name']);
        return null;
    }
    return $profile;
}

function safe_external_catalog_condition(string $alias = 'p'): string
{
    $safeAlias = preg_replace('/[^a-z0-9_]/i', '', $alias) ?: 'p';
    return "LOWER(COALESCE({$safeAlias}.categoria,'')) NOT LIKE '%cerveza%'
        AND LOWER(COALESCE({$safeAlias}.categoria,'')) NOT LIKE '%vino%'
        AND LOWER(COALESCE({$safeAlias}.categoria,'')) NOT LIKE '%licor%'
        AND LOWER({$safeAlias}.producto) NOT REGEXP 'cerveza|vino|vodka|whisk|pisco|ron([^a-z]|$)|licor|cigar|tabaco|nicotin|vape|cannabis|energizante|energy|red bull|monster'";
}

function stock_label(int $stock, int $minimum): string
{
    if ($stock <= 0) {
        return 'Agotado';
    }

    if ($stock <= $minimum) {
        return 'Stock bajo';
    }

    return 'Disponible';
}

function stock_class(int $stock, int $minimum): string
{
    if ($stock <= 0) {
        return 'danger';
    }

    if ($stock <= $minimum) {
        return 'warning';
    }

    return 'success';
}

function category_icon(string $category): string
{
    $icons = [
        'Snacks' => 'S',
        'Dulces y chicles' => 'D',
        'Bebidas' => 'B',
        'Papas y chifles' => '🥔',
        'Canchita y snacks' => '🍿',
        'Galletas' => '🍪',
        'Chocolates y dulces' => '🍫',
        'Frutos secos' => '🥜',
        'Gaseosas' => '🥤',
        'Aguas' => '💧',
        'Jugos' => '🧃',
        'Bebidas rehidratantes' => '⚡',
        'Otros' => 'O',
    ];

    return $icons[$category] ?? (mb_strtoupper(mb_substr($category, 0, 1)) ?: 'D');
}

function payment_label(string $method): string
{
    return [
        'efectivo' => 'Efectivo',
        'yape' => 'Yape',
        'plin' => 'Plin',
        'tarjeta' => 'Tarjeta',
        'transferencia' => 'Transferencia',
        'otro' => 'Otro',
    ][$method] ?? ucfirst($method);
}

function payment_requires_review(string $method): bool
{
    return in_array($method, ['yape', 'plin'], true);
}

function payment_initial_status(string $method): string
{
    if (payment_requires_review($method)) return 'pendiente';
    if ($method === 'efectivo') return 'aprobado';
    throw new InvalidArgumentException('El método de pago requiere un flujo de confirmación propio.');
}

function product_profitability(array $product): array
{
    $stock = max(0, (int) ($product['stock'] ?? 0));
    $price = max(0.0, (float) ($product['price'] ?? 0));
    $recordedCost = max(0.0, (float) ($product['cost_price'] ?? 0));
    $movementUnits = max(0, (int) ($product['purchased_units'] ?? 0));
    $movementTotal = max(0.0, (float) ($product['movement_purchase_total'] ?? 0));
    $hasPurchases = $movementUnits > 0;
    $purchaseQuantity = $hasPurchases ? $movementUnits : $stock;
    $purchaseTotal = $hasPurchases ? $movementTotal : $recordedCost * $stock;
    $unitCost = $hasPurchases ? $movementTotal / $movementUnits : $recordedCost;
    $unitProfit = $price - $unitCost;
    $margin = $price > 0 ? ($unitProfit / $price) * 100 : 0.0;

    return [
        'purchase_quantity' => $purchaseQuantity,
        'purchase_total' => $purchaseTotal,
        'unit_cost' => $unitCost,
        'unit_profit' => $unitProfit,
        'margin' => $margin,
        'sale_potential' => $price * $stock,
        'potential_profit' => $unitProfit * $stock,
        'uses_movement_history' => $hasPurchases,
    ];
}

function movement_label(string $type): string
{
    return [
        'entrada' => 'Compra / entrada',
        'salida' => 'Venta / salida',
        'ajuste_entrada' => 'Ajuste positivo',
        'ajuste_salida' => 'Ajuste negativo',
    ][$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function movement_class(string $type): string
{
    return in_array($type, ['entrada', 'ajuste_entrada'], true) ? 'success' : 'danger';
}

function valid_datetime_local(string $value): ?string
{
    $date = DateTime::createFromFormat('Y-m-d\\TH:i', $value);
    return $date && $date->format('Y-m-d\\TH:i') === $value ? $date->format('Y-m-d H:i:s') : null;
}

function old(string $key, mixed $default = ''): string
{
    return e($_SESSION['old'][$key] ?? $default);
}

function pull_errors(): array
{
    $errors = $_SESSION['errors'] ?? [];
    unset($_SESSION['errors'], $_SESSION['old']);
    return is_array($errors) ? $errors : [];
}

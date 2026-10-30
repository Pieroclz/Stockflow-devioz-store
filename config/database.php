<?php

declare(strict_types=1);

date_default_timezone_set('America/Lima');

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : trim((string) $value);
}

// Configuracion escrita por el instalador web (no debe publicarse en repositorios).
$installedSettings = is_file(__DIR__ . '/installed.php') ? require __DIR__ . '/installed.php' : [];
if (!is_array($installedSettings)) { $installedSettings = []; }
function setting_value(string $key, string $default = ''): string
{
    global $installedSettings;
    return env_value($key, isset($installedSettings[$key]) ? (string) $installedSettings[$key] : $default);
}
define('APP_ENV', setting_value('APP_ENV', 'local'));
define('APP_DEBUG', setting_value('APP_DEBUG', APP_ENV === 'local' ? '1' : '0') === '1');
define('DB_HOST', setting_value('DB_HOST', 'localhost'));
define('DB_PORT', setting_value('DB_PORT', '3306'));
define('DB_NAME', setting_value('DB_NAME', 'devioz_shop_reparada'));
define('DB_USER', setting_value('DB_USER', 'root'));
define('DB_PASS', setting_value('DB_PASS', ''));

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/stockflow'));
$configuredBaseUrl = setting_value('APP_BASE_URL');
$basePath = $configuredBaseUrl !== ''
    ? '/' . trim((string) parse_url($configuredBaseUrl, PHP_URL_PATH), '/')
    : (preg_replace('#/(admin|api|motorizado)$#', '', $scriptDirectory) ?: '/stockflow');
define('BASE_URL', rtrim($basePath, '/'));

function url(string $path = ''): string
{
    return BASE_URL . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $connection = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('StockFlow database connection failed: ' . $exception->getMessage());
        http_response_code(500);
        if (!APP_DEBUG) {
            exit('<main style="font-family:system-ui;max-width:640px;margin:80px auto;padding:32px"><h1>Servicio temporalmente no disponible</h1><p>Inténtalo nuevamente en unos minutos.</p></main>');
        }
        exit(
            '<div style="font-family:system-ui;max-width:720px;margin:80px auto;padding:32px;border:1px solid #fed7aa;border-radius:18px;background:#fff7ed">' .
            '<h1 style="margin-top:0;color:#9a3412">No se pudo conectar con MySQL</h1>' .
            '<p>Verifica que Apache y MySQL estén activos en XAMPP y que hayas importado <strong>database/devioz_shop_reparada.sql</strong> para una instalación nueva.</p>' .
            '<p style="color:#7c2d12">Base esperada: devioz_shop_reparada; usuario root y contraseña vacía.</p>' .
            '</div>'
        );
    }

    return $connection;
}

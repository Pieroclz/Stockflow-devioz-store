<?php
declare(strict_types=1);
// Instalador web para hosting compartido. NO subir a produccion una vez completado.
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$lock = __DIR__ . '/config/install.lock';
$settingsFile = __DIR__ . '/config/installed.php';
$alreadyInstalled = is_file($lock) || is_file($settingsFile);
$messages = [];
$success = false;
if (empty($_SESSION['stockflow_installer_token'])) { $_SESSION['stockflow_installer_token'] = bin2hex(random_bytes(24)); }
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function importSql(PDO $pdo, string $file): int {
    $fh = fopen($file, 'rb');
    if (!$fh) { throw new RuntimeException('No se pudo abrir el archivo SQL: ' . basename($file)); }
    $buffer = '';
    $count = 0;
    $quote = '';
    $escaped = false;
    try {
        while (($line = fgets($fh)) !== false) {
            $trimmed = ltrim($line);
            if ($quote === '' && ($trimmed === '' || preg_match('/^(--(?:\s|$)|#)/', $trimmed))) { continue; }
            // El volcado de phpMyAdmin utiliza comentarios ejecutables /*!40101 ... */.
            $length = strlen($line);
            for ($i=0; $i<$length; $i++) {
                $ch = $line[$i];
                if ($quote !== '') {
                    $buffer .= $ch;
                    if ($escaped) { $escaped = false; continue; }
                    if ($ch === '\\') { $escaped = true; continue; }
                    if ($ch === $quote) {
                        if ($i + 1 < $length && $line[$i+1] === $quote) { $buffer .= $line[++$i]; }
                        else { $quote = ''; }
                    }
                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buffer .= $ch; continue; }
                if ($ch === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') { $pdo->exec($statement); $count++; }
                    $buffer = '';
                    continue;
                }
                $buffer .= $ch;
            }
        }
        if (trim($buffer) !== '') { $pdo->exec(trim($buffer)); $count++; }
    } finally { fclose($fh); }
    return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    try {
        if (!hash_equals((string) $_SESSION['stockflow_installer_token'], (string) ($_POST['token'] ?? ''))) {
            throw new RuntimeException('Sesion caducada. Actualiza la pagina e intenta de nuevo.');
        }
        if (!extension_loaded('pdo_mysql')) { throw new RuntimeException('El servidor PHP necesita la extension pdo_mysql.'); }
        $host = trim((string) ($_POST['host'] ?? ''));
        $port = trim((string) ($_POST['port'] ?? '3306'));
        $dbname = trim((string) ($_POST['dbname'] ?? ''));
        $user = trim((string) ($_POST['user'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
        $adminPass = (string) ($_POST['admin_password'] ?? '');
        if ($host === '' || $dbname === '' || $user === '' || $adminUser === '' || strlen($adminPass) < 12) {
            throw new RuntimeException('Completa los campos obligatorios y usa una contrasena de administrador de al menos 12 caracteres.');
        }
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) { throw new RuntimeException('Puerto MySQL no valido.'); }
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $dbname)) { throw new RuntimeException('El nombre de la base solo puede contener letras, numeros y guion bajo.'); }
        if (!is_writable(__DIR__ . '/config')) { throw new RuntimeException('La carpeta config/ no tiene permiso de escritura.'); }
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 12]);
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'')->fetchColumn();
        if ($existing > 0) { throw new RuntimeException('La base de datos no esta vacia. Por seguridad no se sobrescribe ninguna tabla. Utiliza una base nueva vacia.'); }
        $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
        try {
            importSql($pdo, __DIR__ . '/database/devioz_shop_reparada.sql');
            // La instalacion limpia aplica las migraciones funcionales en orden.
            $col = $pdo->query("SHOW COLUMNS FROM delivery_assignments LIKE 'delivery_status'")->fetch();
            if (!$col) { importSql($pdo, __DIR__ . '/database/migracion_logistica_motorizados.sql'); }
            importSql($pdo, __DIR__ . '/database/crear_tabla_incidencias.sql');
            importSql($pdo, __DIR__ . '/database/migracion_aprobacion_incidencias.sql');
            importSql($pdo, __DIR__ . '/database/migracion_multiples_motorizados.sql');
            importSql($pdo, __DIR__ . '/database/migracion_quejas_clientes.sql');
        } finally { $pdo->exec('SET SESSION FOREIGN_KEY_CHECKS=1'); }
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute(['Administrador principal', $adminUser, $hash]);
        $baseUrl = trim((string) ($_POST['base_url'] ?? ''));
        if ($baseUrl !== '' && (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)), ['https','http'], true))) {
            throw new RuntimeException('La URL del sitio es invalida.');
        }
        $settings = ['APP_ENV'=>'production', 'APP_DEBUG'=>'0', 'APP_BASE_URL'=>$baseUrl,
            'DB_HOST'=>$host, 'DB_PORT'=>$port, 'DB_NAME'=>$dbname, 'DB_USER'=>$user, 'DB_PASS'=>$pass];
        $output = "<?php\n// Generado automaticamente por instalar.php. Contiene credenciales: no compartir.\nreturn " . var_export($settings, true) . ";\n";
        if (file_put_contents($settingsFile, $output, LOCK_EX) === false) { throw new RuntimeException('No se pudo guardar config/installed.php.'); }
        @chmod($settingsFile, 0600);
        if (file_put_contents($lock, 'Instalado: ' . gmdate('c') . "\n", LOCK_EX) === false) { throw new RuntimeException('No se pudo generar el bloqueo de instalacion.'); }
        $alreadyInstalled = true;
        $success = true;
    } catch (Throwable $e) {
        error_log('Error en instalador StockFlow: ' . $e->getMessage());
        $messages[] = $e instanceof PDOException ? 'Error al conectar/importar SQL. Revisa las credenciales y usa una base NUEVA vacia para reintentar. Detalle: ' . $e->getMessage() : $e->getMessage();
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Instalar StockFlow</title><style>
body{font:16px system-ui,-apple-system,sans-serif;background:#f3f6fa;color:#1d2939;margin:0;padding:35px 16px}.wrap{max-width:650px;margin:auto;background:white;padding:32px;border-radius:18px;box-shadow:0 8px 28px #162f4814}h1{margin-top:0}label{display:block;font-size:14px;font-weight:650;margin:13px 0 5px}input{width:100%;padding:12px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;font:inherit}button{background:#155eef;color:white;border:0;border-radius:9px;padding:13px 19px;font-size:16px;font-weight:700;cursor:pointer;margin-top:22px}button:hover{background:#004eeb}.notice{background:#eef4ff;padding:15px;border-radius:10px;line-height:1.5}.error{background:#fee4e2;padding:13px;border-radius:10px;margin:14px 0}.ok{background:#dcfae6;padding:16px;border-radius:10px}small{color:#667085}a{color:#155eef}</style></head><body><main class="wrap">
<h1>📦 Instalador de StockFlow</h1>
<?php if ($success): ?>
<div class="ok"><strong>Instalacion completada.</strong><p>La base de datos y la cuenta de administrador se han creado correctamente.</p><p><a href="index.php">Abrir tienda</a> · <a href="login.php">Iniciar sesion como administrador</a></p></div>
<p><strong>Por seguridad elimina ahora instalar.php del hosting.</strong> El sistema tambien ha bloqueado la reinstalacion.</p>
<?php elseif ($alreadyInstalled): ?>
<div class="ok"><strong>StockFlow ya esta instalado.</strong> No se permite reinstalar sobre una configuracion existente.</div><p><a href="index.php">Ir a StockFlow</a></p>
<?php else: ?>
<div class="notice">Antes de empezar, crea <strong>una base MySQL vacia</strong> y un usuario con permisos desde phpMyAdmin o el panel de tu hosting. Este instalador importa todas las tablas y configura StockFlow con un clic. <strong>El SQL público incluye el esquema y datos de catálogo de referencia, pero no incluye cuentas, clientes, pedidos, comprobantes, ubicaciones GPS ni credenciales personales.</strong> Configura tus propios datos comerciales después de instalar.</div>
<?php foreach ($messages as $m): ?><div class="error"><?=h($m)?></div><?php endforeach; ?>
<form method="post" autocomplete="off"><input type="hidden" name="token" value="<?=h($_SESSION['stockflow_installer_token'])?>">
<label for="host">Servidor MySQL</label><input id="host" name="host" value="<?=h((string)($_POST['host']??'localhost'))?>" required>
<label for="port">Puerto MySQL</label><input id="port" name="port" value="<?=h((string)($_POST['port']??'3306'))?>" required>
<label for="dbname">Nombre de base de datos</label><input id="dbname" name="dbname" value="<?=h((string)($_POST['dbname']??''))?>" placeholder="u123456_stockflow" required>
<label for="user">Usuario MySQL</label><input id="user" name="user" value="<?=h((string)($_POST['user']??''))?>" required>
<label for="password">Contraseña MySQL</label><input id="password" type="password" name="password" required>
<label for="base_url">URL publica de la tienda</label><input id="base_url" name="base_url" type="url" placeholder="https://tudominio.com/stockflow" value="<?=h((string)($_POST['base_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/instalar.php')), '/'))))?>">
<label for="admin_user">Usuario administrador nuevo</label><input id="admin_user" name="admin_user" required autocomplete="off">
<label for="admin_password">Contraseña administrador (12 caracteres como minimo)</label><input id="admin_password" type="password" name="admin_password" minlength="12" required autocomplete="new-password">
<button type="submit">Instalar StockFlow y base de datos</button></form>
<p><small>La importacion puede tardar. No actualices la pagina mientras se procesa. En caso de fallo parcial, crea otra base vacia e intenta nuevamente. El instalador no elimina tablas.</small></p>
<?php endif; ?></main></body></html>

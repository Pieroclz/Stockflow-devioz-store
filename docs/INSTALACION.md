# Instalación local con XAMPP

## 1. Preparar el entorno

1. Instala XAMPP.
2. Inicia Apache y MySQL.
3. Copia la carpeta `stockflow` dentro de `htdocs`.
4. Comprueba que la dirección sea `http://localhost/stockflow/` y que no exista una carpeta duplicada como `htdocs/stockflow/stockflow/`.

## 2. Crear la base de datos vacía

1. Abre `http://localhost/phpmyadmin`.
2. Crea una base de datos llamada `devioz_shop_reparada` (o utiliza otro nombre que recuerdes).
3. Selecciona una intercalación `utf8mb4` cuando phpMyAdmin lo solicite.
4. No importes ningún SQL manualmente: el instalador lo hará.

## 3. Ejecutar el instalador

1. Abre `http://localhost/stockflow/instalar.php`.
2. Introduce el servidor MySQL (normalmente `localhost`), puerto (`3306`), base, usuario y contraseña.
3. En XAMPP, el usuario suele ser `root`; la contraseña depende de tu configuración local.
4. Indica la URL base `http://localhost/stockflow`.
5. Crea un usuario y una contraseña de administrador de al menos 12 caracteres.
6. Pulsa **Instalar StockFlow y base de datos** y no cierres la página hasta terminar.

El instalador importa `database/devioz_shop_reparada.sql`, crea las tablas de logística, incidencias, despachos múltiples y quejas, y registra el administrador inicial. Requiere que la base esté vacía y que el usuario MySQL pueda crear y alterar tablas.

## 4. Entrar al sistema

- Tienda: `http://localhost/stockflow/`
- Acceso administrativo: `http://localhost/stockflow/login.php`
- phpMyAdmin: `http://localhost/phpmyadmin`

Usa las credenciales de administrador que definiste durante la instalación. No hay una contraseña predeterminada.

## 5. Después de instalar

- Elimina `instalar.php` del directorio público.
- No publiques `config/installed.php` ni `config/install.lock`.
- Completa los datos de la tienda, métodos de pago, tarifas y productos.
- Sube tus propias imágenes y archivos de negocio.
- Haz una copia de seguridad antes de realizar cambios de esquema.

## Solución de problemas

### No se puede conectar a MySQL

Comprueba que MySQL esté iniciado, que el host/puerto sean correctos y que el usuario tenga acceso a la base seleccionada.

### El instalador dice que la base no está vacía

Crea otra base de datos vacía. El instalador no elimina ni sobrescribe tablas existentes.

### Error por tamaño o tiempo de ejecución

El SQL inicial incluye un catálogo amplio. Usa un entorno local con límites suficientes de ejecución y memoria. En hosting compartido, consulta los límites PHP del plan.

### Error de permisos en `config/`

El instalador debe poder escribir `config/installed.php` y `config/install.lock`. Ajusta los permisos de escritura de esa carpeta durante la instalación y vuelve a restringirlos después.

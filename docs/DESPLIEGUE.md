# Despliegue en un hosting

StockFlow puede desplegarse en un hosting compatible con PHP y MySQL/MariaDB. El procedimiento exacto depende del proveedor.

## Requisitos del servidor

- PHP 8.2 o superior recomendado.
- Extensiones `pdo_mysql`, `mbstring`, `fileinfo`, `json` y `openssl`.
- `curl` recomendado para integraciones externas.
- MySQL 8.0+ o MariaDB 10.4+.
- HTTPS activo.
- Permisos de escritura limitados en `config/` durante la instalación y en los directorios de archivos subidos.

## Procedimiento general

1. Crea una base de datos MySQL vacía y un usuario específico desde el panel del hosting.
2. Sube el contenido de la carpeta del proyecto al directorio web.
3. Configura el dominio y activa HTTPS.
4. Abre `https://tu-dominio/instalar.php` y completa la conexión, URL base y cuenta inicial.
5. Comprueba la tienda, administración, carga de imágenes, pedidos y notificaciones.
6. Elimina `instalar.php` y verifica que `config/installed.php` no sea accesible desde HTTP.
7. Configura las variables de entorno para servicios opcionales.
8. Programa respaldos de MySQL y de los archivos subidos.

No uses la base local de desarrollo como base de producción. No subas respaldos con pedidos o datos personales. El instalador solo acepta una base vacía.

## Servicios externos

Los mapas, rutas, IA y notificaciones dependen de servicios externos y sus límites. Reemplaza los endpoints públicos de demostración por proveedores adecuados para producción antes de recibir tráfico real.

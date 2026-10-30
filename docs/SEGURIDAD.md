# Seguridad y protección de datos

## Antes de publicar

- Activa HTTPS y redirección a HTTPS.
- Establece `APP_ENV=production` y `APP_DEBUG=0`.
- Elimina `instalar.php` después de instalar.
- No publiques `config/installed.php`, `config/install.lock`, `.env`, archivos locales de IA ni copias de seguridad.
- No subas fotos de clientes, comprobantes, avatares, recibos, documentos ni exportaciones de la base de datos.
- Usa contraseñas únicas y robustas para administradores, clientes y repartidores.
- Configura permisos mínimos para el usuario MySQL.
- Mantén PHP, MySQL/MariaDB y XAMPP actualizados.
- Restringe el acceso web a `config/` y `database/`; las reglas `.htaccess` incluidas son una defensa adicional, no sustituyen la configuración del servidor.

## Datos privados

La base de datos de producción puede contener nombres, teléfonos, direcciones, pedidos, comprobantes, ubicaciones y credenciales cifradas. Nunca la subas al repositorio. El SQL de ejemplo incluido en este repositorio ha sido limpiado de datos operativos y credenciales personales.

## Archivos subidos

Los archivos que cargan los usuarios deben almacenarse en directorios no ejecutables, validarse por MIME/tamaño y servirse mediante rutas controladas. No publiques el contenido de `assets/uploads/` ni `uploads/`.

## Reportar una vulnerabilidad

No publiques credenciales ni detalles explotables en un issue público. Utiliza la función de reporte privado de vulnerabilidades de GitHub si está habilitada para el repositorio; de lo contrario, contacta de forma privada a los mantenedores antes de divulgar el problema.

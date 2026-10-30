# StockFlow | Devioz Store

Plataforma web de comercio e inventario para una tienda minorista, desarrollada con **PHP, MySQL/MariaDB, HTML, CSS y JavaScript**. StockFlow reúne el catálogo público, la gestión del negocio, los pedidos y las operaciones de entrega en una sola aplicación.

> Proyecto preparado para ejecutarse localmente con XAMPP. La instalación inicial se realiza desde el navegador mediante un asistente; no se incluyen cuentas, clientes, pedidos, comprobantes ni credenciales reales.

## Características principales

### Tienda y experiencia de compra
- Catálogo público adaptable a escritorio y dispositivos móviles.
- Búsqueda por nombre, marca, categoría y código de producto.
- Fichas de producto, galería de imágenes, ofertas y combos.
- Carrito/lista de compra con cantidades, presentaciones y resumen de importes.
- Cuenta de cliente, direcciones, favoritos e historial de pedidos.
- Cupones, zonas de entrega y reservas temporales de stock.
- Seguimiento del estado del pedido y recibo digital.

### Administración del negocio
- Dashboard con indicadores de ventas, inventario, rentabilidad y actividad.
- Gestión de productos, categorías, precios, ofertas, combos e imágenes.
- Control de stock, movimientos de inventario y alertas de existencias.
- Gestión de pedidos, revisión de comprobantes y estados de preparación/entrega.
- Gestión de clientes, usuarios y roles administrativos.
- Reportes, estadísticas, satisfacción del cliente y copias de respaldo.
- Gestión de incidencias y quejas, con revisión administrativa y registro de decisiones.

### Catálogo y herramientas complementarias
- Catálogo maestro y comparación de precios de proveedores/supermercados.
- Datos de productos de referencia incluidos en el volcado SQL de instalación.
- Asistente de compras con proveedor IA opcional y alternativa basada en reglas.
- Consulta nutricional y datos complementarios de productos.
- Notificaciones opcionales por Telegram.

### Logística
- Gestión de despachos y seguimiento de entregas.
- Asignación de uno o varios repartidores a un pedido cuando la operación lo requiere.
- Registro de incidencias y reclamos vinculados a pedidos.
- Mapa con OpenStreetMap y cálculo de rutas mediante servicios externos configurables.

## Tecnologías

- **Backend:** PHP 8.2+ (recomendado), PDO.
- **Base de datos:** MySQL 8.0+ o MariaDB 10.4+.
- **Frontend:** HTML5, CSS3 y JavaScript.
- **Servidor local:** Apache mediante XAMPP.
- **Mapas:** Leaflet + teselas de OpenStreetMap; el cálculo de rutas usa el servicio OSRM configurado en el componente.

## Requisitos

- Windows, macOS o Linux.
- XAMPP con Apache, PHP y MySQL/MariaDB, o un servidor equivalente.
- PHP con las extensiones `pdo_mysql`, `mbstring`, `fileinfo`, `json` y `openssl` habilitadas.
- `curl` recomendado para integraciones externas (IA y notificaciones).
- Navegador moderno y conexión a Internet para mapas, recursos externos y servicios opcionales.

## Instalación rápida con XAMPP

1. Descarga o clona este repositorio.
2. Copia la carpeta `stockflow` dentro de `htdocs` de XAMPP. La ruta habitual en Windows es `C:\xampp\htdocs\stockflow`.
3. Inicia **Apache** y **MySQL** desde el panel de XAMPP.
4. Abre phpMyAdmin y crea una base de datos vacía, por ejemplo `devioz_shop_reparada`.
5. Abre `http://localhost/stockflow/instalar.php`.
6. Completa el host, puerto, base de datos, usuario y contraseña de MySQL; define también la URL local y las credenciales del primer administrador.
7. Pulsa **Instalar StockFlow y base de datos** y espera a que finalice el proceso.
8. Entra a `http://localhost/stockflow/login.php` con el usuario y la contraseña que acabas de crear.
9. **Elimina `instalar.php` después de completar la instalación.** El instalador también crea un bloqueo para impedir una segunda instalación accidental.

El instalador exige una base de datos vacía y crea el esquema, aplica las migraciones incluidas y registra el primer administrador. No importes el SQL manualmente antes de ejecutar el instalador.

La guía detallada está en [`docs/INSTALACION.md`](docs/INSTALACION.md).

## Primeros pasos

1. Accede al panel administrativo.
2. Revisa los datos generales de la tienda y completa los datos comerciales propios.
3. Comprueba las categorías, productos, precios y stock inicial.
4. Configura los métodos de pago que realmente utilizarás y verifica los datos receptores.
5. Ajusta las zonas y tarifas de entrega, cupones y contenido de la tienda.
6. Realiza una compra de prueba y revisa el flujo completo antes de publicar el sitio.

Consulta [`docs/OPERACION.md`](docs/OPERACION.md) para conocer los módulos principales y [`docs/CONFIGURACION.md`](docs/CONFIGURACION.md) para las integraciones opcionales.

## Configuración y variables de entorno

El instalador guarda la conexión en `config/installed.php`, archivo local que **no debe subirse a GitHub**. En otros entornos se pueden configurar variables de entorno como `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV`, `APP_DEBUG` y `APP_BASE_URL`.

Las claves de servicios externos (IA, pasarela de pago, etc.) deben configurarse en el entorno del servidor o en archivos locales ignorados por Git. Nunca escribas claves privadas, tokens ni contraseñas en el repositorio.

Más información: [`docs/CONFIGURACION.md`](docs/CONFIGURACION.md) y [`docs/SEGURIDAD.md`](docs/SEGURIDAD.md).

## Estructura del proyecto

```text
stockflow/
├── admin/          # Módulos del área administrativa
├── api/            # Endpoints internos e integraciones
├── assets/         # CSS, JavaScript, imágenes y recursos públicos
├── config/         # Configuración local y ejemplos (sin secretos)
├── database/       # Esquema inicial y migraciones SQL
├── docs/           # Guías de instalación, operación y configuración
├── includes/       # Funciones compartidas, acceso a datos y componentes
├── motorizado/     # Área operativa de entregas
├── tests/          # Pruebas automatizadas disponibles
├── index.php       # Entrada de la tienda pública
├── instalar.php    # Asistente de instalación inicial
└── login.php       # Acceso administrativo
```

## Base de datos y datos de demostración

`database/devioz_shop_reparada.sql` contiene el esquema de la aplicación y datos de catálogo de referencia. Para proteger la privacidad, el archivo distribuido en este repositorio no incluye registros de clientes, cuentas, pedidos, pagos, comprobantes, ubicaciones GPS, movimientos privados ni tokens personales.

La instalación crea tablas vacías para la operación de cada tienda. El administrador debe cargar sus propios datos, productos, imágenes, credenciales y configuraciones comerciales.

## Integraciones y limitaciones conocidas

- **Mapas:** OpenStreetMap necesita conexión a Internet y debe conservarse su atribución. El servicio público de OSRM incluido para pruebas no debe considerarse una infraestructura de producción ni garantiza tráfico en tiempo real.
- **Asistente IA:** puede trabajar con Ollama local; OpenAI u otro proveedor requiere credenciales configuradas fuera del repositorio. Si el proveedor no está disponible, la aplicación puede recurrir al modo basado en reglas.
- **Telegram:** integración opcional que se configura desde el panel de pagos/notificaciones.
- **Pagos:** el flujo de Yape permite registrar el comprobante para revisión administrativa; efectivo y otros métodos dependen de la configuración del negocio. La ruta de integración de tarjeta/Culqi presente en el código debe revisarse y completarse con credenciales y pruebas del proveedor antes de ofrecerla en producción; no se debe asumir que está lista para cobrar en vivo.

## Pruebas

El directorio `tests/` contiene pruebas PHP y JavaScript para partes del catálogo, carrito, pagos, recomendaciones y panel. Ejecuta las pruebas aplicables desde la raíz del proyecto, por ejemplo:

```bash
php tests/combo_builder_test.php
php tests/culqi_payment_test.php
```

Algunas pruebas requieren extensiones PHP o dependencias del entorno. No todas las pruebas constituyen una prueba integral de extremo a extremo con MySQL.

## Seguridad y datos

- No subas `config/installed.php`, contraseñas, tokens, claves privadas, respaldos de producción ni archivos de `assets/uploads/` o `uploads/`.
- Usa HTTPS en producción.
- Cambia todas las credenciales iniciales y configura claves propias.
- Haz respaldos antes de migrar o actualizar la base de datos.
- Revisa [`SECURITY.md`](SECURITY.md) para reportar vulnerabilidades.

## Documentación

- [Instalación local](docs/INSTALACION.md)
- [Operación de la tienda](docs/OPERACION.md)
- [Configuración e integraciones](docs/CONFIGURACION.md)
- [Seguridad y datos](docs/SEGURIDAD.md)
- [Publicación en hosting](docs/DESPLIEGUE.md)
- [Contribuir al proyecto](CONTRIBUTING.md)

## Licencia

Este repositorio no incluye todavía una licencia de código abierto. La disponibilidad pública en GitHub no concede por sí sola permisos de reutilización, distribución o modificación. Añade una licencia explícita cuando los titulares del proyecto definan sus condiciones.

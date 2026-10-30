# Configuración

## Configuración de base de datos y aplicación

El instalador guarda los datos de conexión en `config/installed.php`. Ese archivo es local y está excluido de Git. En servidores donde se administren variables de entorno, StockFlow reconoce:

| Variable | Descripción | Ejemplo |
|---|---|---|
| `APP_ENV` | Entorno | `production` |
| `APP_DEBUG` | Mostrar errores en pantalla | `0` |
| `APP_BASE_URL` | URL base, sin barra final | `https://dominio.example` |
| `DB_HOST` | Host MySQL | `localhost` |
| `DB_PORT` | Puerto MySQL | `3306` |
| `DB_NAME` | Base de datos | `devioz_shop_reparada` |
| `DB_USER` | Usuario MySQL | `stockflow_user` |
| `DB_PASS` | Contraseña MySQL | (secreto del servidor) |

En producción usa `APP_DEBUG=0`, HTTPS y credenciales MySQL con permisos limitados.

## Asistente de compras e IA

El archivo `config/shopping_agent.php` define el proveedor. Se pueden configurar variables de entorno:

- `SHOPPING_AI_PROVIDER`: `ollama`, `openai` o modo compatible con la configuración del proyecto.
- `SHOPPING_AI_MODEL`: nombre del modelo.
- `OPENAI_API_KEY`: clave privada si se utiliza OpenAI.
- `SHOPPING_OLLAMA_URL`: URL del servicio Ollama.
- `SHOPPING_OLLAMA_TOKEN`: token si se utiliza un endpoint remoto protegido.

Para ajustes locales también existe `config/shopping_agent.local.example.php`. Copia el ejemplo a `config/shopping_agent.local.php` y modifica los valores. El archivo local está excluido de Git. No expongas una clave privada en JavaScript ni en el repositorio.

Ollama debe estar instalado y ejecutándose por separado. Si el proveedor de IA no responde, algunas funciones utilizan recomendaciones basadas en reglas.

## Pagos

- **Yape:** el flujo permite que el comprador adjunte un comprobante y que el administrador lo revise.
- **Efectivo:** depende de las opciones y zonas habilitadas por el negocio.
- **Plin:** se configura desde los ajustes de pago cuando se habilita para la tienda.
- **Culqi/tarjetas:** hay componentes de configuración y endpoints en el código, pero la integración de cobro debe completarse y certificarse con las credenciales y documentación del proveedor antes de usarla en producción.

No incluyas claves privadas, secretos de webhook ni tokens de pago en archivos versionados.

## Telegram

Las notificaciones son opcionales y se configuran desde el área administrativa de pagos. El token del bot y el Chat ID se guardan en la configuración de la tienda. No publiques esos valores ni capturas que los muestren.

## Mapas y rutas

El mapa utiliza Leaflet y teselas de OpenStreetMap. El cálculo de rutas se realiza mediante el servicio OSRM configurado en el componente. Los servicios públicos tienen límites y no garantizan tráfico en tiempo real; para producción se debe contratar o desplegar un proveedor de rutas adecuado. Respeta las condiciones de uso y atribución de cada proveedor.

## Ajuste de ETA

`config/eta_calibration.php` permite configurar una estimación calibrada. Un factor fijo no equivale a información de tráfico en tiempo real y debe mostrarse como estimación, no como promesa de llegada.

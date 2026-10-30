# Contribuir

Gracias por tu interés en contribuir a StockFlow.

## Antes de enviar cambios

1. Abre un issue describiendo el problema o la mejora.
2. Trabaja en una rama separada.
3. Mantén las credenciales fuera del código y de los commits.
4. No incluyas bases de datos reales, imágenes de clientes, comprobantes ni archivos de configuración local.
5. Prueba los cambios en una instalación limpia cuando afecten el instalador o el esquema SQL.
6. Describe los archivos modificados y los pasos para reproducir la prueba.

## Estilo

- Mantén la compatibilidad con la versión de PHP indicada en el README.
- Usa PDO y consultas preparadas para datos externos.
- Valida entradas en el servidor; la validación del navegador no es suficiente.
- Protege las acciones de escritura con autenticación, autorización y CSRF.
- Evita cambios destructivos de base de datos. Incluye una migración documentada cuando sea necesaria.

## Pull requests

Explica el propósito, el comportamiento esperado, cómo probarlo y cualquier migración o configuración adicional.

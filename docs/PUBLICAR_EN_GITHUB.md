# Publicar StockFlow en GitHub

## Datos sugeridos del repositorio

- **Nombre:** `stockflow-devioz-store`
- **Descripción corta:** `Plataforma web de comercio e inventario en PHP y MySQL: catálogo, carrito, pedidos, administración y logística de entregas.`
- **Topics:** `php`, `mysql`, `mariadb`, `ecommerce`, `inventory-management`, `store-management`, `xampp`, `leaflet`, `javascript`, `peru`.

## Crear el repositorio

1. En GitHub, crea un repositorio nuevo.
2. No agregues README, `.gitignore` ni licencia desde GitHub si ya usarás los archivos de esta carpeta.
3. Descomprime el ZIP y abre una terminal en la carpeta `stockflow`.
4. Ejecuta:

```bash
git init
git add .
git status
```

5. Revisa `git status` y confirma que no aparezcan `config/installed.php`, archivos `.env`, imágenes de clientes, comprobantes, avatares ni respaldos.
6. Realiza el primer commit y conecta el repositorio remoto:

```bash
git commit -m "Publica la versión inicial de StockFlow"
git branch -M main
git remote add origin https://github.com/TU_USUARIO/stockflow-devioz-store.git
git push -u origin main
```

Sustituye `TU_USUARIO` por tu usuario real de GitHub.

## Antes de hacerlo público

- Revisa el contenido del catálogo de referencia y confirma que puedes redistribuir esos datos, imágenes y enlaces conforme a las condiciones de sus fuentes.
- No subas la base de datos de tu instalación local. El SQL incluido en este paquete fue saneado, pero cualquier exportación posterior de producción debe permanecer privada.
- No subas credenciales, tokens, comprobantes, avatares ni datos de compradores.
- El repositorio no incluye una licencia; decide y añade una licencia si quieres otorgar permisos explícitos de reutilización.

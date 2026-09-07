# ---- Etapa 1: compila el frontend (React/Vite) Y pdf-render (Node) ----
# Se replica la misma estructura relativa que en el repo real
# (resources/frontend junto a public/) para que las rutas relativas de
# vite.config.js (publicDirectory: '../../public', outDir del PWA) resuelvan
# igual que en local.
#
# pdf-render se compila AQUÍ (no en la etapa final) para no tener que instalar
# npm en la imagen de PHP: solo el binario `node` viaja a la etapa final (ver
# más abajo por qué copiar npm específicamente sí daba problemas).
FROM node:20-slim AS frontend-build
WORKDIR /build
COPY resources/frontend resources/frontend
COPY public public
WORKDIR /build/resources/frontend
RUN npm ci
RUN npm run build

WORKDIR /build/pdf-render
COPY pdf-render/package.json pdf-render/package-lock.json ./
RUN npm ci
COPY pdf-render .
# npm prune quita esbuild y demás devDependencies tras el build: en runtime
# solo hace falta lo que dist/*.cjs importa (react, @react-pdf/renderer, etc).
RUN npm run build && npm prune --omit=dev

# ---- Etapa 2: imagen final PHP/Apache (sirve la API y los assets ya compilados) ----
FROM php:8.4-apache

# Paquetes de sistema mínimos (no PHP): zip/unzip para composer, git/curl por
# si algún paquete los necesita en su instalación. Todo en una sola capa con
# limpieza de /var/lib/apt/lists al final para no dejar el índice de apt en
# la imagen.
RUN apt-get update && apt-get install -y --no-install-recommends \
    zip unzip git curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Extensiones de PHP vía install-php-extensions (mlocati): usa binarios
# precompilados cuando existen en vez de compilar cada extensión desde
# código fuente (docker-php-ext-install) — mucho más rápido y liviano en
# RAM durante el build, que es justo lo que agotaba la memoria del VPS.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mbstring exif pcntl bcmath gd

# Activar el módulo de reescritura de Apache para Laravel (mod_rewrite)
RUN a2enmod rewrite

# Instalar Composer de forma global
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Copiar los archivos del backend al contenedor
COPY . .

# Instalar dependencias de Laravel ignorando restricciones de plataforma.
# --no-cache evita que composer deje su cache de paquetes dentro de la capa.
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-cache --ignore-platform-reqs

# Trae los assets del frontend ya compilados en la Etapa 1 (JS/CSS con hash,
# manifest de Vite, service worker).
COPY --from=frontend-build /build/public/build ./public/build
COPY --from=frontend-build /build/public/sw.js ./public/sw.js
COPY --from=frontend-build /build/public/workbox-*.js ./public/

# Node en la imagen final: NodeRenderService (App\Services\NodeRenderService)
# invoca `node` en tiempo de ejecución para generar PDFs (factura, orden de
# compra, recibo de pago) y el HTML del correo genérico — no es solo un paso
# de build como el frontend, así que no se puede quitar Node de esta imagen.
# Pero como pdf-render ya se compiló en la Etapa 1, aquí solo hace falta el
# binario `node` (no npm/npx: no se vuelve a instalar nada en runtime). Copiar
# solo `node` evita el problema que sí daba copiar `npm` suelto (ver historial:
# npm 20+ resuelve rutas internas relativas a su propio árbol de instalación).
COPY --from=frontend-build /usr/local/bin/node /usr/local/bin/node
COPY --from=frontend-build /build/pdf-render/dist ./pdf-render/dist
COPY --from=frontend-build /build/pdf-render/node_modules ./pdf-render/node_modules

# Configurar los permisos correctos para Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Asegurar permisos también para la carpeta database (SQLite)
RUN mkdir -p /var/www/html/database \
    && chown -R www-data:www-data /var/www/html/database \
    && chmod -R 775 /var/www/html/database

# Apuntar Apache a la carpeta pública de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Exponer el puerto HTTP estándar (el VPS lo mapea/proxya según corresponda)
EXPOSE 80

# Script de arranque: migraciones + seeders + storage:link + worker de colas
# (queue:work con auto-reinicio) + Apache en primer plano.
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh
CMD ["bash", "/usr/local/bin/start.sh"]

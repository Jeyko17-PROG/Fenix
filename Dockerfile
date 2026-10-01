# ---- Etapa 1: compila el frontend (React/Vite, cliente + SSR para PDFs/correos) ----
FROM node:20-slim AS frontend-build
WORKDIR /build
COPY resources resources
COPY public public
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
RUN npm run build
# npm prune quita vite/eslint/tailwind (devDependencies): en runtime el bundle
# SSR (bootstrap/ssr/*.js) sigue necesitando node_modules de verdad porque sus
# dependencias (react, @react-pdf/renderer, @react-email/*) quedan externas al
# bundle — ver docblock de app/Shared/Infrastructure/NodeRenderService.php.
RUN npm prune --omit=dev

# ---- Etapa 2: imagen final PHP/Apache (sirve la API y los assets ya compilados) ----
FROM php:8.4-apache

# Paquetes de sistema mínimos (no PHP): zip/unzip para composer, git/curl por
# si algún paquete los necesita en su instalación.
RUN apt-get update && apt-get install -y --no-install-recommends \
    zip unzip git curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Extensiones de PHP vía install-php-extensions (mlocati): usa binarios
# precompilados en vez de compilar cada extensión desde código fuente — mucho
# más rápido y liviano en RAM durante el build (el VPS es un CX23 de 4GB y ya
# tuvo problemas de memoria compilando extensiones desde cero).
# pdo_pgsql: base de datos de la app. pdo_mysql: solo lo usa el comando
# artisan migrar:logix (migración one-off desde la base vieja), se puede
# quitar después de completada esa migración si se quiere una imagen más chica.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd

# Activar el módulo de reescritura de Apache para Laravel (mod_rewrite)
RUN a2enmod rewrite

# Instalar Composer de forma global
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar el proyecto al contenedor
COPY . .

# Instalar dependencias de Laravel (sin dev, autoloader optimizado)
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-cache --ignore-platform-reqs

# Assets del frontend ya compilados en la Etapa 1: JS/CSS con hash + manifest
# de Vite (public/build), el service worker del PWA, y el bundle SSR
# (bootstrap/ssr/*.js) + el node_modules que ese bundle necesita en runtime.
COPY --from=frontend-build /build/public/build ./public/build
COPY --from=frontend-build /build/public/sw.js ./public/sw.js
COPY --from=frontend-build /build/public/workbox-*.js ./public/
COPY --from=frontend-build /build/bootstrap/ssr ./bootstrap/ssr
COPY --from=frontend-build /build/node_modules ./node_modules
COPY --from=frontend-build /usr/local/bin/node /usr/local/bin/node

# Permisos correctos para Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Apuntar Apache a la carpeta pública de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80

# Script de arranque: migraciones + seeders + storage:link + worker de colas
# + scheduler (auto-reinicio) + Apache en primer plano.
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh
CMD ["bash", "/usr/local/bin/start.sh"]

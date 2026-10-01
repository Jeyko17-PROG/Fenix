#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/var/www/html"

echo "=== Iniciando tareas de arranque del contenedor ==="

mkdir -p "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"

echo "Limpiando caches de Laravel..."
php artisan config:clear || true
php artisan cache:clear || true
php artisan route:clear || true
php artisan view:clear || true

echo "Ejecutando migraciones y seeders..."
php artisan migrate --force || true
php artisan db:seed --force || true

# Los PDFs de facturas/órdenes se sirven por /storage/...; el disco es
# efímero (vive en un volumen aparte), así que el enlace hay que recrearlo
# en cada arranque.
php artisan storage:link --force || php artisan storage:link || true

# Reintenta trabajos que fallaron en arranques anteriores.
php artisan queue:retry all || true

echo "Lanzando worker de colas (queue:work)..."
(
  while true; do
    php artisan queue:work --sleep=3 --tries=3 --timeout=60 || true
    echo "queue:work terminó; reiniciando en 5s..."
    sleep 5
  done
) &

# Sin esto, las tareas programadas (routes/console.php — recordatorios de
# membresía, etc.) nunca se disparan: no hay cron del sistema dentro del
# contenedor.
echo "Lanzando scheduler de Laravel (schedule:work)..."
(
  while true; do
    php artisan schedule:work || true
    echo "schedule:work terminó; reiniciando en 5s..."
    sleep 5
  done
) &

echo "=== Tareas completadas con éxito — Lanzando Apache ==="
exec apache2-foreground

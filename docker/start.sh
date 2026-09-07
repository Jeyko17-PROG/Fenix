#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/var/www/html"
DB_DIR="$PROJECT_DIR/database"
WEB_USER="www-data"

echo "=== Iniciando tareas de arranque del contenedor ==="

# Asegurar directorios base
mkdir -p "$DB_DIR"
mkdir -p "$PROJECT_DIR/storage"
mkdir -p "$PROJECT_DIR/bootstrap/cache"

# --- Bloque legado SQLite: SOLO aplica si la conexión es sqlite ---
# (En producción la BD es MySQL vía variables de entorno; este bloque no
#  debe pisar DB_DATABASE cuando se usa mysql.)
if [ "${DB_CONNECTION:-mysql}" = "sqlite" ]; then
  if [ -f "$DB_DIR/database.sqlite" ]; then
    echo "Copiando database.sqlite a /tmp para habilitar escritura..."
    cp "$DB_DIR/database.sqlite" /tmp/database.sqlite || true
  else
    echo "No se encontró base de datos previa, creando una vacía en /tmp..."
    touch /tmp/database.sqlite || true
  fi
  chmod 777 /tmp || true
  chmod 777 /tmp/database.sqlite || true
  chown "$WEB_USER":"$WEB_USER" /tmp/database.sqlite || true
  export DB_DATABASE=/tmp/database.sqlite
fi
# ------------------------------------------------------------------

# Limpiar absolutamente todas las cachés viejas
echo "Limpiando caches de Laravel..."
php artisan config:clear || true
php artisan cache:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Ejecutar migraciones y seeders (idempotentes)
echo "Ejecutando migraciones y seeders..."
php artisan migrate --force || true
php artisan db:seed --force || true

# Enlace público de storage (los PDFs de facturas se sirven por /storage/...).
# El disco es efímero: el enlace hay que recrearlo en cada arranque.
php artisan storage:link --force || php artisan storage:link || true

# Reintentar los correos que fallaron en arranques anteriores.
php artisan queue:retry all || true

# Worker de la cola: procesa los correos (facturas, recibos) en segundo plano.
# Con auto-reinicio: si el worker muere, se relanza a los 5 segundos.
echo "Lanzando worker de correos (queue:work)..."
(
  while true; do
    php artisan queue:work --sleep=3 --tries=3 --timeout=60 || true
    echo "queue:work terminó; reiniciando en 5s..."
    sleep 5
  done
) &

# Scheduler de Laravel (routes/console.php): sin esto, tareas programadas
# como el recordatorio de membresía por vencer (8am/4pm) nunca se disparan
# — no hay cron del sistema operativo dentro del contenedor.
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

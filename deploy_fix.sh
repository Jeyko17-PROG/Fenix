#!/usr/bin/env bash
set -euo pipefail

# --- CONFIGURE BEFORE RUNNING ---
PROJECT_DIR="/var/www/html"            # ruta al proyecto Laravel (ajusta si es otra)
WEB_USER="www-data"                    # usuario del servidor web (www-data/nginx/apache)
ADMIN_EMAIL="admin@local"              # email para Super Admin a crear/actualizar
ADMIN_PASS="NuevaClaveSegura123!"      # contraseña para Super Admin
BACKUP_DIR="${PROJECT_DIR}/backups"    # donde guardan los backups
# -------------------------------

echo "Proyecto: $PROJECT_DIR"
if [ ! -d "$PROJECT_DIR" ]; then
  echo "ERROR: proyecto no encontrado en $PROJECT_DIR"
  exit 1
fi

cd "$PROJECT_DIR"

mkdir -p "$BACKUP_DIR"
echo "Backup dir: $BACKUP_DIR"

# Load .env values (if present)
if [ -f .env ]; then
  DB_CONNECTION=$(grep -E '^DB_CONNECTION=' .env | cut -d'=' -f2- || true)
  DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d'=' -f2- || true)
  DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d'=' -f2- || true)
  DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | cut -d'=' -f2- || true)
  DB_PASSWORD=$(grep -E '^DB_PASSWORD=' .env | cut -d'=' -f2- || true)
else
  echo ".env no encontrado en $PROJECT_DIR — saliendo"
  exit 1
fi

timestamp() { date +"%F_%H%M%S"; }

echo "DB_CONNECTION=$DB_CONNECTION"
if [ "$DB_CONNECTION" = "sqlite" ]; then
  SQLITE_PATH="${DB_DATABASE:-database/database.sqlite}"
  if [[ "$SQLITE_PATH" != /* ]]; then
    SQLITE_PATH="$PROJECT_DIR/$SQLITE_PATH"
  fi

  echo "Usando SQLite: $SQLITE_PATH"
  if [ ! -f "$SQLITE_PATH" ]; then
    echo "Archivo SQLite no existe — creando..."
    sudo -u "$WEB_USER" mkdir -p "$(dirname "$SQLITE_PATH")"
    sudo -u "$WEB_USER" touch "$SQLITE_PATH"
  fi

  echo "Ajustando permisos del directorio de base de datos..."
  sudo chown -R "$WEB_USER":"$WEB_USER" "$(dirname "$SQLITE_PATH")"
  sudo chmod -R 775 "$(dirname "$SQLITE_PATH")"
  sudo chown "$WEB_USER":"$WEB_USER" "$SQLITE_PATH"
  sudo chmod 664 "$SQLITE_PATH"

  echo "Haciendo copia del fichero sqlite..."
  cp "$SQLITE_PATH" "$BACKUP_DIR/database.sqlite.$(timestamp)"
  echo "Backup creado: $BACKUP_DIR/database.sqlite.$(timestamp)"
elif [ "$DB_CONNECTION" = "mysql" ] || [ "$DB_CONNECTION" = "mariadb" ]; then
  if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
    echo "Faltan credenciales MySQL en .env. No puedo hacer mysqldump automáticamente."
  else
    BACKUP_FILE="$BACKUP_DIR/${DB_DATABASE}_$(timestamp).sql"
    echo "Haciendo mysqldump de ${DB_DATABASE} -> $BACKUP_FILE"
    mysqldump -h "${DB_HOST:-127.0.0.1}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" > "$BACKUP_FILE" || {
      echo "mysqldump falló. Comprueba credenciales o instala cliente MySQL."
    }
    echo "Backup MySQL creado: $BACKUP_FILE"
  fi
else
  echo "DB_CONNECTION='$DB_CONNECTION' no reconocido; saltando backup DB automático."
fi

echo "Limpiando caches y ejecutando migraciones..."
if ! command -v php >/dev/null 2>&1; then
  echo "php no encontrado en PATH. Instálalo o ajusta el PATH."
  exit 1
fi

sudo chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache || true
sudo chmod -R 775 storage bootstrap/cache || true

php artisan down || true
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

if [ -f composer.json ]; then
  composer install --no-dev --optimize-autoloader || echo "composer install retornó con error; revisa composer.lock"
fi

echo "Ejecutando migraciones (forzadas)..."
php artisan migrate --force

php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "Creando/actualizando Super Admin: $ADMIN_EMAIL"
php artisan tinker --execute " \
$u = App\\Models\\User::where('email', '${ADMIN_EMAIL}')->first(); \
if ($u) { $u->password = bcrypt('${ADMIN_PASS}'); $u->es_super_admin = 1; $u->estado='ACTIVO'; $u->activo=1; $u->save(); echo 'Super Admin actualizado\n'; } \
else { App\\Models\\User::create(['name'=>'Super Admin','email'=>'${ADMIN_EMAIL}','password'=>bcrypt('${ADMIN_PASS}'),'es_super_admin'=>1,'estado'=>'ACTIVO','activo'=>1]); echo 'Super Admin creado\n'; }"

echo "Reiniciando servicios web si están instalados..."
if systemctl list-units --type=service --all | grep -q php; then
  PHP_FPM_NAME=$(systemctl list-units --type=service --all | grep -E 'php[0-9\.]*-fpm' | awk '{print $1}' | head -n1 || true)
  if [ -n "$PHP_FPM_NAME" ]; then
    echo "Restart $PHP_FPM_NAME"
    sudo systemctl restart "$PHP_FPM_NAME" || echo "No se pudo reiniciar $PHP_FPM_NAME"
  else
    echo "No se detectó php-fpm service por nombre automático."
  fi
fi

if systemctl is-active --quiet nginx; then
  echo "Reiniciando nginx..."
  sudo systemctl restart nginx || echo "Fallo al reiniciar nginx"
elif systemctl is-active --quiet apache2; then
  echo "Reiniciando apache2..."
  sudo systemctl restart apache2 || echo "Fallo al reiniciar apache2"
else
  echo "No se detectó nginx ni apache2 activos; omitiendo reinicio webserver."
fi

php artisan up || true

echo "Mostrando últimas 80 líneas de log (storage/logs/laravel.log):"
tail -n 80 storage/logs/laravel.log || echo "No se pudo leer laravel.log"

echo "Hecho. Intenta iniciar sesión con $ADMIN_EMAIL / $ADMIN_PASS"

php artisan migrate --force

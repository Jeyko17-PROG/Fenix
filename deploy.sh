#!/usr/bin/env bash
set -euo pipefail

# Deploy de Fenix (Docker Compose) en el mismo VPS donde corre Logix.MD.
# A propósito SOLO levanta app+db (no nginx/certbot ni puertos 80/443): el
# objetivo es poder construir y probar Fenix sin tocar el tráfico real de
# Logix. El corte real (nginx apuntando a Fenix en vez de a Logix) es un
# paso manual aparte, ver el plan de migración.
#
# Uso:
#   REPO_URL=https://github.com/otro-usuario/otro-repo.git BRANCH=otra-rama ./deploy.sh
# Reintentar el script es seguro: no repite pasos que ya quedaron hechos.

REPO_URL="${REPO_URL:-https://github.com/Jeyko17-PROG/Fenix.git}"
APP_DIR="${APP_DIR:-$HOME/fenix}"
BRANCH="${BRANCH:-main}"

echo "==> Repo: $REPO_URL -> $APP_DIR (rama: $BRANCH)"
if [ -d "$APP_DIR/.git" ]; then
  echo "==> Ya existe, actualizando..."
  git -C "$APP_DIR" fetch origin "$BRANCH"
  git -C "$APP_DIR" checkout "$BRANCH"
  git -C "$APP_DIR" pull origin "$BRANCH"
else
  echo "==> Clonando..."
  git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"

# ---- .env ----
if [ ! -f .env ]; then
  cp .env.example .env
  KEY="base64:$(openssl rand -base64 32)"
  sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" .env
  echo
  echo "!! Se creó .env desde .env.example (con un APP_KEY nuevo)."
  echo "!! Edítalo ahora con los valores reales: DOMAIN, APP_URL, FRONTEND_URL,"
  echo "!! DB_* (usuario/clave para el Postgres de este stack, no el de Logix),"
  echo "!! MAIL_*, WOMPI_*, CERTBOT_EMAIL. Luego vuelve a correr este script."
  exit 0
fi

# ---- Construir y levantar SOLO app+db (sin nginx/certbot todavía) ----
echo "==> Construyendo y levantando app+db..."
docker compose -f docker-compose.prod.yml up -d --build db app

echo
echo "==> Listo. App corriendo en la red interna 'fenix', sin exponer a Internet todavía."
echo "    Logs:  docker compose -f docker-compose.prod.yml logs -f app"
echo "    Probar dentro de la red: docker compose -f docker-compose.prod.yml exec app curl -sI http://localhost"
echo "    El corte real (nginx + certificado) es un paso aparte — ver el plan de migración."

#!/bin/ash
# One-shot bootstrap for the local Pelican dev stack. Idempotent.
set -eu

log() { echo "[provision] $*"; }

cd /var/www/html

log "waiting for postgres at ${DB_HOST}:${DB_PORT}"
until nc -z "${DB_HOST}" "${DB_PORT}"; do sleep 1; done

log "waiting for redis at ${REDIS_HOST}:${REDIS_PORT}"
until nc -z "${REDIS_HOST}" "${REDIS_PORT}"; do sleep 1; done

mkdir -p \
  /pelican-data/database \
  /pelican-data/storage/avatars \
  /pelican-data/storage/fonts \
  /pelican-data/storage/icons \
  /var/www/html/storage/logs/supervisord \
  "$(dirname "${WINGS_CONFIG_PATH}")"

# APP_KEY lives in the shared data volume so the panel container reuses it and
# encrypted columns (such as the node daemon token) stay readable.
if [ ! -s /pelican-data/.env ]; then
  log "generating APP_KEY"
  printf 'APP_KEY=base64:%s\n' "$(head -c 32 /dev/urandom | base64)" > /pelican-data/.env
fi

log "running migrations and seeders"
php artisan migrate --force --seed --no-interaction

log "creating admin user, node, allocations and Wings config"
php /provision/provision.php

for dir in /var/www/html/plugins/*/; do
  [ -f "${dir}plugin.json" ] || continue
  id=$(basename "${dir}")
  log "installing plugin '${id}'"
  php artisan p:plugin:install "${id}" --no-interaction || log "plugin '${id}' already installed, skipping"
done

log "finished"

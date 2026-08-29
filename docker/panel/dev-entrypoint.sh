#!/bin/ash
# Dev wrapper around the upstream panel entrypoint.
#
# The upstream entrypoint runs `filament:optimize` before starting supervisord.
# That cache freezes the discovered Filament resources/pages/widgets, so a
# resource added by a plugin only shows up after a rebuild. For plugin
# development we run the upstream setup, then drop those caches again.
set -e

# `/bin/true` is passed as the command so the upstream script does its setup
# (env bootstrap, migrations, plugin composer deps) without starting anything.
/bin/ash /entrypoint.sh /bin/true

echo "[dev] clearing caches that would hide plugin changes"
php artisan filament:optimize-clear || true
php artisan icons:clear || true
php artisan view:clear || true
php artisan config:clear || true
php artisan route:clear || true
php artisan event:clear || true

# Re-export what supervisord and the Caddyfile expect; the upstream entrypoint
# set these in its own subshell.
export SUPERVISORD_CADDY=true
# Listen on every hostname so both http://localhost and the .test name work.
export CADDY_APP_URL=":80"
export CADDY_LE_EMAIL=""
export CADDY_AUTO_HTTPS=""
export CADDY_TRUSTED_PROXIES=""
export CADDY_STRICT_PROXIES=""

echo "[dev] starting supervisord"
exec "$@"

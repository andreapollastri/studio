#!/usr/bin/env bash
# Every screenshot of the docs, from a throwaway demo: a SQLite database of its own (your
# database is never touched), the fake staging site, a demo workspace with the bridge on it,
# and three Studios on the same database through router.php (git hosts and clouds answer from
# fake-apis.php): the local driver for most pages, the native driver with a stable and a beta
# updater state for the System page. Needs Google Chrome, Node 20+, sqlite3 and built assets
# (npm run build).
#
#   tools/screenshots/run.sh [work dir] [jobs file] [out dir]   → docs/img/*.png by default
#   SHOTS_SSH_PORT=8092 tools/screenshots/run.sh …               → the SSH gateway elsewhere, when 8082 is taken
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORK=${1:-$(mktemp -d)}
JOBS=$(cd "$(dirname "${2:-$ROOT/tools/screenshots/jobs.json}")" && pwd)/$(basename "${2:-jobs.json}")
OUT=${3:-$ROOT/docs/img}
mkdir -p "$WORK"
DB="$WORK/demo.sqlite"; LOGS="$WORK/logs"; mkdir -p "$LOGS"
PIDS=()
GW=${SHOTS_SSH_PORT:-8082}
cleanup() { for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null || true; done; pkill -f "studio-shots-" 2>/dev/null || true; }
trap cleanup EXIT
for port in 4455 $GW 8766 8767 8768 8769 9333; do
    if lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then echo "port $port is in use: stop what listens there first" >&2; exit 1; fi
done

export DB_CONNECTION=sqlite DB_DATABASE="$DB" STUDIO_DOMAIN=dev.agency.test STUDIO_WORKSPACE_DRIVER=local \
  STUDIO_LOCAL_BRIDGE_URL=http://127.0.0.1:4455 STUDIO_LOCAL_BRIDGE_TOKEN=local-bridge-token \
  STUDIO_LOCAL_APP_URL=http://127.0.0.1:8766/preview/ DEMO_STAGING_URL=http://127.0.0.1:8766 DEMO_PREVIEW_URL=http://127.0.0.1:8766/preview/ \
  STUDIO_SERVER_IPS=203.0.113.10 APP_URL=http://127.0.0.1:8767 QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log CACHE_STORE=file SESSION_DRIVER=file \
  STUDIO_SSH_URL=ws://127.0.0.1:$GW/ssh/ws

rm -f "$DB"; touch "$DB"
(cd "$ROOT" && php artisan migrate --force --seed --seeder=DemoSeeder >"$LOGS/seed.log")

# the fake staging site and preview
(cd "$ROOT/tools/screenshots/fake-staging" && exec python3 -m http.server 8766 --bind 127.0.0.1 >"$LOGS/staging.log" 2>&1) & PIDS+=($!)

# a throwaway workspace and the bridge on it
"$ROOT/tools/screenshots/make-demo-workspace.sh" "$WORK/workspace" >/dev/null
# its Terminal shell: bash with a home of its own, so the shots show a workspace user's prompt, not yours
mkdir -p "$WORK/home"
cat >"$WORK/home/.bash_profile" <<'SH'
PS1='\[\e[1;32m\]ws-maria\[\e[0m\]:\[\e[1;34m\]shop-acme\[\e[0m\] \[\e[33m\]($(git branch --show-current 2>/dev/null))\[\e[0m\] $ '
SH
(cd "$ROOT" && exec env BRIDGE_TOKEN=local-bridge-token BRIDGE_HOST=127.0.0.1 WORKSPACE_DIR="$WORK/workspace" \
    HOME="$WORK/home" BRIDGE_SHELL=/bin/bash BASH_SILENCE_DEPRECATION_WARNING=1 node bridge/bin/bridge.js >"$LOGS/bridge.log" 2>&1) & PIDS+=($!)

# the updater states the System page reads
NOW=$(date -u +%Y-%m-%dT%H:%M:%SZ)
cat >"$WORK/state.json" <<JSON
{"channel":"stable","current":"v2.2.0","latest":"v2.3.0","target":"v2.3.0","update_available":true,"auto":true,"checked_at":"$NOW","status":"done","message":"Updated v2.1.2 → v2.2.0.","finished_at":"$NOW","system":{"status":"done","finished_at":"$NOW","upgraded":["caddy","php8.4-fpm","mariadb-server"],"reboot":false}}
JSON
cat >"$WORK/state-beta.json" <<JSON
{"channel":"beta","current":"main-1a2b3c4","latest":"main-5d6e7f8","target":"main-5d6e7f8","update_available":true,"auto":true,"checked_at":"$NOW","status":"done","message":"Updated to main-1a2b3c4.","finished_at":"$NOW"}
JSON

serve() { # port, extra env…
    local port=$1; shift
    (cd "$ROOT" && exec env "$@" php -S "127.0.0.1:$port" -t public tools/screenshots/router.php >"$LOGS/studio-$port.log" 2>&1) & PIDS+=($!)
}
# the home of the SSH page's server: its file manager reads this folder (router.php)
SFTP_HOME="$WORK/sftp-home"
mkdir -p "$SFTP_HOME"/{shop-acme/{app/Http/Controllers,config,public,resources/views,storage/logs},backups,logs}
printf 'APP_NAME="Shop Acme"\nAPP_ENV=production\nAPP_URL=https://shop-acme.example.com\nDB_CONNECTION=mariadb\nDB_DATABASE=shopacme\nDB_USERNAME=shopacme\nQUEUE_CONNECTION=redis\n' >"$SFTP_HOME/shop-acme/.env"
printf '# Shop Acme\n\nThe shop of Acme: Laravel 13, Livewire, MariaDB.\n' >"$SFTP_HOME/shop-acme/README.md"
printf '{\n    "name": "acme/shop",\n    "require": {\n        "php": "^8.4",\n        "laravel/framework": "^13.0"\n    }\n}\n' >"$SFTP_HOME/shop-acme/composer.json"
printf '<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::view(\x27/\x27, \x27welcome\x27);\n' >"$SFTP_HOME/shop-acme/public/index.php"
printf '[2026-10-21 09:12:03] production.INFO: Order 1042 paid\n[2026-10-21 09:14:41] production.WARNING: Slow query on orders (812 ms)\n' >"$SFTP_HOME/shop-acme/storage/logs/laravel.log"
for f in OrderController ProductController CheckoutController; do printf '<?php\n\nnamespace App\\Http\\Controllers;\n\nclass %s extends Controller\n{\n}\n' "$f" >"$SFTP_HOME/shop-acme/app/Http/Controllers/$f.php"; done
printf '<?php\n\nreturn [\n    \x27name\x27 => env(\x27APP_NAME\x27, \x27Shop Acme\x27),\n];\n' >"$SFTP_HOME/shop-acme/config/app.php"
head -c 2048 /dev/urandom >"$SFTP_HOME/backups/shopacme-2026-10-20.sql.gz"
head -c 4096 /dev/urandom >"$SFTP_HOME/backups/shopacme-2026-10-21.sql.gz"
printf 'export PATH="$HOME/bin:$PATH"\n' >"$SFTP_HOME/.bashrc"
serve 8767 DEMO_SFTP_HOME="$SFTP_HOME"
# the SSH gateway, for the Terminal tab's shell: it redeems tickets at the Studio above
(cd "$ROOT" && exec env SSH_GATEWAY_PORT="$GW" APP_KEY="$(sed -n 's/^APP_KEY=//p' .env)" SSH_GATEWAY_REDEEM_URL=http://127.0.0.1:8767/api/ssh/redeem SSH_GATEWAY_ORIGIN=http://127.0.0.1:8767 \
    node ssh-gateway/bin/gateway.js >"$LOGS/gateway.log" 2>&1) & PIDS+=($!)
NATIVE=(STUDIO_WORKSPACE_DRIVER=native STUDIO_HELPER="$ROOT/tests/fixtures/fake-studio-admin" STUDIO_HELPER_SUDO=false)
# the stable one has PHP 8.4 added to its 8.5 (Administration → System → PHP), 8.3 still on offer
serve 8768 "${NATIVE[@]}" STUDIO_UPDATE_STATE="$WORK/state.json" STUDIO_PHP_VERSIONS=8.4,8.5
serve 8769 "${NATIVE[@]}" STUDIO_UPDATE_STATE="$WORK/state-beta.json"
sleep 2

q() { sqlite3 "$DB" "$1"; }
sed -e "s#\${BASE}#http://127.0.0.1:8767#g" -e "s#\${BASE2}#http://127.0.0.1:8768#g" -e "s#\${BASE3}#http://127.0.0.1:8769#g" \
    -e "s#\${CONV_PERMISSION}#$(q "select uuid from conversations where title = 'Date range filter on orders'")#g" \
    -e "s#\${CONV_PLAN}#$(q "select uuid from conversations where title = 'Release 1.4: what is missing'")#g" \
    -e "s#\${PROJECT_SHOP}#$(q "select uuid from projects where slug = 'shop-acme'")#g" \
    -e "s#\${MCP_ERRORS}#$(q "select uuid from project_mcp_servers where name = 'errors'")#g" \
    -e "s#\${VAULT_TOOLS}#$(q "select uuid from vault_folders where name = 'Agency tools'")#g" \
    -e "s#\${VAULT_SHOP}#$(q "select uuid from vault_folders where name = 'Shop Acme'")#g" \
    -e "s#\${RES_CIPI}#$(q "select uuid from project_resources where resource_id = 'shopacme'")#g" \
    -e "s#\${RES_DROPLET}#$(q "select uuid from project_resources where resource_id = '424242'")#g" \
    -e "s#\${ACCOUNT_CIPI}#$(q "select uuid from connector_accounts where connector = 'cipi'")#g" \
    -e "s#\${DB_LOCAL}#$(q "select uuid from database_connections where name = 'Shop Acme · local copy'")#g" \
    -e "s#\${JOB_SITE}#$(q "select uuid from backup_jobs where name = 'Shop Acme · site and database'")#g" \
    -e "s#\${SSH_CIPI}#$(q "select uuid from ssh_connections where name = 'Shop Acme · Cipi'")#g" \
    "$JOBS" >"$WORK/jobs.json"

(cd "$ROOT/tools/screenshots" && node shots.mjs "$WORK/jobs.json" "$OUT")

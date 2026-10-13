#!/usr/bin/env bash
#
# The pure helpers of installer/studio-domain: which hosts and which lines of an app's .env
# move to the new domain, and which stay (the rest asks DNS, Caddy and Studio: a real server).
#
#   bash tests/installer/studio-domain.test.sh
#
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
T=$(mktemp -d "${TMPDIR:-/tmp}/studio-domain-test.XXXXXX")
trap 'rm -rf "$T"' EXIT
# shellcheck disable=SC1091
. "$HERE/../../installer/lib/system.sh"
# shellcheck disable=SC1091
STUDIO_DOMAIN_LIB=1 . "$HERE/../../installer/studio-domain"

fail() { printf '\033[31mFAIL\033[0m %s\n' "$*"; exit 1; }
ok() { printf '\033[32mok\033[0m   %s\n' "$*"; }

[ "$(rehost https://shop.dev.example.test dev.example.test agency.test)" = https://shop.agency.test ] || fail "rehost a URL"
[ "$(rehost https://a-b.dev.example.test:443/x dev.example.test agency.test)" = https://a-b.agency.test:443/x ] || fail "rehost with a port and a path"
[ "$(rehost 'a.dev.example.test,b.dev.example.test' dev.example.test agency.test)" = 'a.agency.test,b.agency.test' ] || fail "rehost a list"
[ "$(rehost shop.mydev.example.test dev.example.test agency.test)" = shop.mydev.example.test ] || fail "a longer name is not under the domain"
[ "$(rehost shop.dev.example.test.org dev.example.test agency.test)" = shop.dev.example.test.org ] || fail "a name that goes on is not under the domain"
[ "$(rehost shop.devXexample.test dev.example.test agency.test)" = shop.devXexample.test ] || fail "the dots of the domain are literal"
ok "rehost moves <name>.OLD and nothing else"

cat > "$T/ws-andrea--shop.caddy" <<'CADDY'
@ws_andrea_shop host andrea-shop.dev.example.test
handle @ws_andrea_shop {
    root * /srv/studio/workspaces/andrea/shop/public
    php_fastcgi unix//run/php/studio-ws-andrea--shop.sock {
        env APP_ENV local
    }
    file_server
}
CADDY
app=$(rehost_site "$T/ws-andrea--shop.caddy" dev.example.test agency.test)
[ "$app" = /srv/studio/workspaces/andrea/shop ] || fail "rehost_site prints the app directory, got $app"
[ "$(head -n1 "$T/ws-andrea--shop.caddy")" = '@ws_andrea_shop host andrea-shop.agency.test' ] || fail "rehost_site: $(head -n1 "$T/ws-andrea--shop.caddy")"
grep -q '        env APP_ENV local' "$T/ws-andrea--shop.caddy" || fail "rehost_site leaves the other lines as they are"
ok "rehost_site moves the host of a site file and says where its app is"

printf 'APP_NAME=Shop\nAPP_URL=https://shop.dev.example.test\nMAIL_FROM_ADDRESS=hello@dev.example.test\nREVERB_HOST=shop.dev.example.test\nSESSION_DOMAIN=\nVITE_REVERB_HOST="${REVERB_HOST}"\n' > "$T/.env"
rehost_env "$T/.env" dev.example.test agency.test
[ "$(env_get "$T/.env" APP_URL)" = https://shop.agency.test ] || fail "rehost_env APP_URL"
[ "$(env_get "$T/.env" REVERB_HOST)" = shop.agency.test ] || fail "rehost_env REVERB_HOST"
[ "$(env_get "$T/.env" MAIL_FROM_ADDRESS)" = hello@dev.example.test ] || fail "rehost_env must leave the app's own mail alone"
[ "$(env_get "$T/.env" SESSION_DOMAIN)" = "" ] || fail "rehost_env leaves an empty key empty"
grep -qx 'VITE_REVERB_HOST="${REVERB_HOST}"' "$T/.env" || fail "rehost_env leaves the other lines as they are"
ok "rehost_env moves the app's own hosts, not the rest of its .env"

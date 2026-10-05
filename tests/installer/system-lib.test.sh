#!/usr/bin/env bash
#
# The pure helpers of installer/lib/system.sh (the rest installs packages and
# only shows itself on a real server).
#
#   bash tests/installer/system-lib.test.sh
#
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
T=$(mktemp -d "${TMPDIR:-/tmp}/studio-lib-test.XXXXXX")
trap 'rm -rf "$T"' EXIT
# shellcheck disable=SC1091
. "$HERE/../../installer/lib/system.sh"

fail() { printf '\033[31mFAIL\033[0m %s\n' "$*"; exit 1; }
ok() { printf '\033[32mok\033[0m   %s\n' "$*"; }

printf 'A=1\nSTUDIO_GIT_URL=https://x.test/a=b\nB="quoted value"\n' > "$T/env"
env_set "$T/env" A 2
env_set "$T/env" STUDIO_PHP_VERSIONS 8.3,8.4,8.5
env_set "$T/env" STUDIO_GIT_URL https://dev.azure.com/acme
[ "$(env_get "$T/env" A)" = 2 ] || fail "env_set replace"
[ "$(env_get "$T/env" STUDIO_PHP_VERSIONS)" = 8.3,8.4,8.5 ] || fail "env_set append"
[ "$(env_get "$T/env" STUDIO_GIT_URL)" = https://dev.azure.com/acme ] || fail "env_set with = and / in values"
[ "$(env_get "$T/env" B)" = "quoted value" ] || fail "env_get unquotes"
[ "$(grep -c '^A=' "$T/env")" = 1 ] || fail "env_set duplicated a key"
ok "env_set / env_get"

printf 'root __DIR__/public\nphp __PHP__ __PHP__\n' > "$T/tpl"
render_tpl "$T/tpl" "$T/out" DIR=/opt/studio/current PHP=php8.4
[ "$(cat "$T/out")" = "$(printf 'root /opt/studio/current/public\nphp php8.4 php8.4')" ] || fail "render_tpl: $(cat "$T/out")"
ok "render_tpl replaces every placeholder"

echo old > "$T/dest"; exec 3<"$T/dest"
echo new > "$T/src"
install_file "$T/src" "$T/dest" 0755
[ "$(cat "$T/dest")" = new ] || fail "install_file content"
[ "$(cat <&3)" = old ] || fail "install_file must not rewrite the old inode (a running script reads it)"
[ -x "$T/dest" ] || fail "install_file mode"
ok "install_file swaps the file without touching the one in use"

# The PHP versions of this machine stay out of it (a CI runner has its own PHP-FPM in /etc/php).
installed_php_versions() { printf '%s' "${FAKE_PHP_VERSIONS:-}"; }

mkdir -p "$T/rel/installer"
printf 'STUDIO_PHP=8.5\nPHP_VERSIONS="8.4 8.5 8.6"\nNODE_MAJOR=24\n' > "$T/rel/installer/requirements.env"
load_requirements "$T/rel"
[ "$STUDIO_PHP $NODE_MAJOR" = "8.5 24" ] && [ "$PHP_VERSIONS" = "8.4 8.5 8.6" ] || fail "load_requirements: $STUDIO_PHP $NODE_MAJOR / $PHP_VERSIONS"
ok "a release's requirements.env is what the system step installs"

printf 'STUDIO_PHP=9.9\n' > "$T/rel/installer/requirements.env"
FAKE_PHP_VERSIONS=8.3,8.4 load_requirements "$T/rel"
[ "$STUDIO_PHP" = 8.4 ] || fail "a PHP that is not installed falls back to the newest one that is, got $STUDIO_PHP"
ok "a PHP the server does not have falls back to the newest it has"

[ "$(env_get "$HERE/../../installer/requirements.env" STUDIO_PHP)" != "" ] || fail "installer/requirements.env has no STUDIO_PHP"
ok "installer/requirements.env is readable"

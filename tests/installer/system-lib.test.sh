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

# The official installer trusts HOME of the shell that runs it. A prefix on curl alone
# would install into this process's home and never link agent.
mkdir -p "$T/path" "$T/bin"
cat > "$T/path/curl" <<'EOF'
#!/bin/sh
printf 'installer\n'
EOF
cat > "$T/path/bash" <<'EOF'
#!/bin/sh
cat >/dev/null
mkdir -p "$HOME/.local/bin"
printf '#!/bin/sh\nexit 0\n' > "$HOME/.local/bin/agent"
chmod +x "$HOME/.local/bin/agent"
printf '%s\n' "$HOME" > "$HOME/seen-home"
EOF
chmod +x "$T/path/curl" "$T/path/bash"
STUDIO_APT_LOG="$T/apt.log" CURSOR_CLI_HOME="$T/cli" CURSOR_CLI_BIN="$T/bin/agent" PATH="$T/path:$PATH" ensure_cursor_cli
[ "$(cat "$T/cli/seen-home")" = "$T/cli" ] || fail "the installer ran with HOME=$(cat "$T/cli/seen-home" 2>/dev/null || echo missing), not the shared directory"
[ -x "$T/cli/.local/bin/agent" ] || fail "agent was not installed in the shared directory"
[ -L "$T/bin/agent" ] && [ "$(readlink "$T/bin/agent")" = "$T/cli/.local/bin/agent" ] || fail "agent was not linked onto the PATH"
ok "the Cursor CLI installs into the shared directory and is linked onto the PATH"

printf 'caddy 2.10.0\nlinux-image-6.8.0-85 6.8.0-85.85\nphp8.4-fpm 8.4.12-1\nredis-server 7.0.15\n' > "$T/before"
printf 'caddy 2.10.2\nlinux-image-6.8.0-90 6.8.0-90.90\nphp8.4-fpm 8.4.13-1\nredis-server 7.0.15\n' > "$T/after"
changes=$(changed_versions "$T/before" "$T/after")
expected=$(printf 'caddy 2.10.0 → 2.10.2\nlinux-image-6.8.0-85 6.8.0-85.85 (removed)\nlinux-image-6.8.0-90 6.8.0-90.90 (new)\nphp8.4-fpm 8.4.12-1 → 8.4.13-1')
[ "$changes" = "$expected" ] || fail "changed_versions: $changes"
[ -z "$(changed_versions "$T/after" "$T/after")" ] || fail "changed_versions with nothing changed"
ok "changed_versions lists what a server update upgraded, added and removed"

day=86400
[ "$(cert_state 0 $((90 * day)) $((10 * day)))" = ok ] || fail "cert_state ok"
[ "$(cert_state 0 $((90 * day)) $((65 * day)))" = due ] || fail "cert_state due"
[ "$(cert_state 0 $((90 * day)) $((80 * day)))" = late ] || fail "cert_state late"
[ "$(cert_state 0 $((90 * day)) $((91 * day)))" = expired ] || fail "cert_state expired"
[ "$(cert_state 0 $((6 * day)) $((3 * day)))" = ok ] && [ "$(cert_state 0 $((6 * day)) $((5 * day)))" = late ] || fail "cert_state on a 6-day certificate"
ok "cert_state: ok, due, late, expired, in fractions of the lifetime"

openssl req -x509 -newkey rsa:2048 -nodes -keyout "$T/key.pem" -out "$T/cert.pem" -days 90 -subj /CN=shop.dev.example.test >/dev/null 2>&1
start=$(cert_epoch "$T/cert.pem" startdate); end=$(cert_epoch "$T/cert.pem" enddate)
[ $((end - start)) = $((90 * day)) ] && [ $((start - $(date +%s))) -le 60 ] || fail "cert_epoch: $start $end"
ok "cert_epoch reads the dates of a real certificate"

# check_certificates with Caddy's storage in a directory: each certificate's dates in <file>.dates,
# "wanted" hosts in $T/wanted, a handshake renews a host listed in $T/renewable
now=$(date +%s)
cert_epoch() { local d; d=$(cat "$1.dates"); if [ "$2" = startdate ]; then echo "${d% *}"; else echo "${d#* }"; fi; }
host_wanted() { grep -qx "$1" "$T/wanted"; }
renew_by_handshake() {
    echo "$1" >> "$T/handshakes"
    grep -qx "$1" "$T/renewable" && echo "$now $((now + 90 * day))" > "$CADDY_CERTIFICATES/le/$1/$1.crt.dates"
    return 0
}
sleep() { :; }
fake_cert() { # fake_cert ISSUER HOST START END
    mkdir -p "$CADDY_CERTIFICATES/$1/$2"; touch "$CADDY_CERTIFICATES/$1/$2/$2.crt"
    echo "$3 $4" > "$CADDY_CERTIFICATES/$1/$2/$2.crt.dates"
}
CADDY_CERTIFICATES=$T/caddy
fake_cert le studio.example.test $((now - 10 * day)) $((now + 80 * day))
fake_cert le shop.example.test $((now - 70 * day)) $((now + 20 * day))
fake_cert le stuck.example.test $((now - 85 * day)) $((now + 5 * day))
fake_cert le gone.example.test $((now - 89 * day)) $((now + 1 * day))
fake_cert le zero.example.test $((now - 89 * day)) $((now + 1 * day))
fake_cert zerossl zero.example.test $((now - 1 * day)) $((now + 89 * day))
printf 'studio.example.test\nshop.example.test\nstuck.example.test\nzero.example.test\n' > "$T/wanted"
echo shop.example.test > "$T/renewable"; : > "$T/handshakes"
check_certificates "$T/report" >/dev/null
report=$(sort "$T/report" | tr '\n' '|')
[ "$report" = "shop.example.test 90 renewed|stuck.example.test 5 late|studio.example.test 80 ok|zero.example.test 89 ok|" ] || fail "check_certificates: $report"
[ "$(sort "$T/handshakes" | tr '\n' ' ')" = "shop.example.test stuck.example.test " ] || fail "handshakes: $(cat "$T/handshakes")"
ok "check_certificates: renews the late ones, reports what stays late, skips hosts Studio no longer serves"

# ensure_php_fixed with apt in files: $T/php/<v> is PHP_VERSION, $T/php/<v>.deb the package's
# version, $T/php/<v>.next what apt upgrades to ("8.4.27 8.4.27-1+deb.sury.org+1")
mkdir -p "$T/php"
php_patch_level() { cat "$T/php/$1" 2>/dev/null || true; }
dpkg-query() {
    local v
    case "$*" in
        *'${Version}'*) v=${*: -1}; v=${v#php}; cat "$T/php/${v%-fpm}.deb" ;;
        *) v=${*: -1}; v=${v#php}; v=${v%-\*}; printf 'ii  php%s-cli\nii  php%s-fpm\nrc  php%s-gone\n' "$v" "$v" "$v" ;;
    esac
}
studio_apt() {
    echo "apt $*" >> "$T/php/log"
    [ "$1" = install ] || return 0
    local v=${*: -1}; v=${v#php}; v=${v%-fpm}
    [ -f "$T/php/$v.next" ] || return 0
    read -r p d < "$T/php/$v.next"; echo "$p" > "$T/php/$v"; echo "$d" > "$T/php/$v.deb"
}
systemctl() { echo "systemctl $*" >> "$T/php/log"; }
restart_outdated_services() { echo needrestart >> "$T/php/log"; }
fake_php() { # fake_php V VERSION DEB [NEXT_VERSION NEXT_DEB]
    echo "$2" > "$T/php/$1"; echo "$3" > "$T/php/$1.deb"; rm -f "$T/php/$1.next"
    [ -z "${4:-}" ] || echo "$4 $5" > "$T/php/$1.next"
}
export PHP_FIXED="8.2.35 8.3.36 8.4.27 8.5.12" APT_UPDATED=1

: > "$T/php/log"
fake_php 8.4 8.4.26 8.4.26-1+deb.sury.org+1 8.4.27 8.4.27-1+deb.sury.org+1
out=$(ensure_php_fixed 8.4 2>&1)
[ "$(cat "$T/php/log")" = "$(printf 'apt install --only-upgrade php8.4-cli php8.4-fpm\nsystemctl restart php8.4-fpm\nneedrestart')" ] || fail "upgrade: $(cat "$T/php/log")"
[[ $out == *"PHP 8.4 is 8.4.27"* ]] || fail "upgrade says: $out"
ok "a PHP before the security fixes goes to its newest package and PHP-FPM restarts"

: > "$T/php/log"
fake_php 8.4 8.4.27 8.4.27-1+deb.sury.org+1
fake_php 8.3 8.3.40 8.3.40-1+deb.sury.org+1
ensure_php_fixed 8.4; ensure_php_fixed 8.3; ensure_php_fixed 8.6
[ ! -s "$T/php/log" ] || fail "a fixed PHP was touched: $(cat "$T/php/log")"
ok "a PHP on the fixes or later, or of a branch the release does not name, is left alone"

: > "$T/php/log"
fake_php 8.5 8.5.11 8.5.11-1+deb.sury.org+1
out=$(ensure_php_fixed 8.5 2>&1)
[ "$(cat "$T/php/log")" = "apt install --only-upgrade php8.5-cli php8.5-fpm" ] || fail "no new build restarted: $(cat "$T/php/log")"
[[ $out == *"ppa:ondrej/php does not serve 8.5.12 yet"* ]] || fail "late PPA says: $out"
ok "while the PPA lacks the fix, nothing restarts and the update says so"

: > "$T/php/log"
fake_php 8.5 8.5.4 8.5.4-1ubuntu1 8.5.4 8.5.4-1ubuntu1.1
out=$(ensure_php_fixed 8.5 2>&1)
[ "$(tail -n1 "$T/php/log")" = needrestart ] || fail "Ubuntu's new build did not restart: $(cat "$T/php/log")"
[[ $out == *"Ubuntu's security fixes keep the version number"* && $out != *"does not serve"* ]] || fail "Ubuntu's PHP says: $out"
ok "Ubuntu's PHP goes to its newest build, whose number stays"

[ "$(env_get "$HERE/../../installer/requirements.env" STUDIO_PHP)" != "" ] || fail "installer/requirements.env has no STUDIO_PHP"
[ "$(env_get "$HERE/../../installer/requirements.env" PHP_FIXED)" != "" ] || fail "installer/requirements.env has no PHP_FIXED"
ok "installer/requirements.env is readable"

#!/usr/bin/env bash
#
# The updater, end to end, in a sandbox: a local git repository with release
# tags and a main branch stands in for GitHub, a directory stands in for /, and
# the system commands (sudo, systemctl, mariadb, php, composer, npm, curl…) are
# stubs that log what they were asked. The releases' installer/lib/system.sh is a
# stub too: the real one installs packages, which only a real server can show.
#
#   bash tests/installer/studio-update.test.sh
#
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
UPDATER="$HERE/../../installer/studio-update"
SANDBOX=$(mktemp -d "${TMPDIR:-/tmp}/studio-update-test.XXXXXX")
STUBS=$SANDBOX/stubs
REMOTE=$SANDBOX/remote
export SANDBOX STUBS
trap 'rm -rf "$SANDBOX"' EXIT

pass=0
fail() { printf '\033[31mFAIL\033[0m %s\n' "$*"; echo "--- calls"; cat "$SANDBOX/calls.log" 2>/dev/null || true; echo "--- log"; tail -n 30 "$SANDBOX/var/log/studio-update.log" 2>/dev/null || true; exit 1; }
ok() { pass=$((pass + 1)); printf '\033[32mok\033[0m   %s\n' "$*"; }
calls() { cat "$SANDBOX/calls.log" 2>/dev/null || true; }
state() { jq -r "$1" "$SANDBOX/var/lib/studio-update/state.json"; }
current_dir() { basename "$(readlink "$SANDBOX/opt/studio/current")"; }
current() { local b; b=$(current_dir); echo "${b%%@*}"; }
update() { STUDIO_UPDATE_SANDBOX=$SANDBOX PATH="$STUBS:$PATH" bash "$UPDATER" "$@" < /dev/null; }
reset_calls() { : > "$SANDBOX/calls.log"; }

# ------------------------------------------------------------------ stubs ---

mkdir -p "$STUBS"
stub() { printf '#!/usr/bin/env bash\n%s\n' "$2" > "$STUBS/$1"; chmod +x "$STUBS/$1"; }

stub sudo 'while [ $# -gt 0 ] && [[ "$1" == -* ]]; do case "$1" in -u) shift 2 ;; *) shift ;; esac; done
if [ "${1:-}" = env ]; then shift; args=(); for a in "$@"; do case "$a" in -i) ;; PATH=*) args+=("PATH=$STUBS:/usr/bin:/bin:/usr/sbin:/sbin") ;; *) args+=("$a") ;; esac; done; exec env "${args[@]}"; fi
exec "$@"'
stub systemctl 'echo "systemctl $*" >> "$SANDBOX/calls.log"'
stub chown ':'
stub sleep ':'
stub mariadb-dump 'echo "mariadb-dump $*" >> "$SANDBOX/calls.log"; echo "-- dump of studio"'
stub mariadb 'if [ "${1:-}" = -e ]; then echo "mariadb $*" >> "$SANDBOX/calls.log"; else echo "mariadb < $(cat)" >> "$SANDBOX/calls.log"; fi'
stub curl 'if [ -f "$SANDBOX/fail-health" ]; then printf 500; else printf 200; fi'
stub composer 'echo "composer $*" >> "$SANDBOX/calls.log"'
stub npm 'echo "npm $*" >> "$SANDBOX/calls.log"; mkdir -p node_modules'
# php8.4 <release dir>/artisan <command> … — a release fails a command when $SANDBOX/fail-<command>-<dir> exists
stub php8.4 'if [ "$(basename "${1:-}")" = composer ]; then exec "$STUBS/composer" "${@:2}"; fi
rel=$(basename "$(dirname "$1")"); echo "artisan $rel ${*:2}" >> "$SANDBOX/calls.log"
[ -f "$SANDBOX/fail-$2-$rel" ] && { echo "artisan $2 failed" >&2; exit 1; }; exit 0'
if [ "$(uname)" = Darwin ]; then
    # the updater targets Ubuntu: GNU mv -T, sed -i and flock, for a Mac
    stub flock 'exit 0'
    stub sed 'if [ "${1:-}" = -i ]; then shift; exec /usr/bin/sed -i "" "$@"; fi; exec /usr/bin/sed "$@"'
    stub mv 'if [ "${1:-}" = -Tf ]; then exec python3 -c "import os,sys; os.replace(sys.argv[1], sys.argv[2])" "$2" "$3"; fi; exec /bin/mv "$@"'
fi

# ----------------------------------------------------------------- remote ---

git init -q -b main "$REMOTE"
git -C "$REMOTE" config user.email test@studio.test; git -C "$REMOTE" config user.name Test
mkdir -p "$REMOTE/installer/lib" "$REMOTE/installer/upgrades" "$REMOTE/storage"
cat > "$REMOTE/installer/lib/system.sh" <<'EOF'
# a stand-in for the real installer/lib/system.sh
ensure_system() { echo "ensure_system $(basename "$1")" >> "$SANDBOX/calls.log"; }
install_release_files() { echo "install_release_files $(basename "$1")" >> "$SANDBOX/calls.log"; }
restart_studio() { echo "restart_studio" >> "$SANDBOX/calls.log"; }
EOF
printf 'STUDIO_PHP=8.4\nPHP_VERSIONS="8.4"\nNODE_MAJOR=22\n' > "$REMOTE/installer/requirements.env"
echo '<?php' > "$REMOTE/artisan"; echo '{}' > "$REMOTE/composer.json"; echo '{}' > "$REMOTE/package.json"; touch "$REMOTE/storage/.keep"

commit() { # commit VERSION MESSAGE
    echo "$1" > "$REMOTE/VERSION"
    git -C "$REMOTE" add -A; git -C "$REMOTE" commit -q --allow-empty -m "$2"
}
release() { commit "${2:-${1#v}}" "$1"; git -C "$REMOTE" tag "$1"; }
upgrade_script() { # upgrade_script VERSION [BODY] — logs its phase
    printf 'echo "upgrade %s $1 FROM=$FROM TO=$TO body=%s" >> "$SANDBOX/calls.log"\n' "$1" "${2:-1}" > "$REMOTE/installer/upgrades/$1.sh"
}

release v1.0.0
upgrade_script 1.1.0
release v1.1.0
release v1.2.0-beta.1 1.2.0-beta.1

# ------------------------------------------------------- installed server ---
# as the installer leaves it: v1.0.0 with its commit, the shared .env

mkdir -p "$SANDBOX/etc/studio" "$SANDBOX/opt/studio/releases" "$SANDBOX/opt/studio/shared/storage" "$SANDBOX/var/lib/studio-update" "$SANDBOX/var/log" "$SANDBOX/var/backups/studio" "$SANDBOX/run/lock"
cat > "$SANDBOX/etc/studio/studio.env" <<EOF
DOMAIN=dev.example.test
STUDIO_HOME=$SANDBOX/opt/studio
STUDIO_DIR=$SANDBOX/opt/studio/current
STUDIO_PHP=8.4
STUDIO_REPO=file://$REMOTE
AUTO_UPDATE=1
UPDATE_CHANNEL=stable
EOF
printf 'DB_CONNECTION=mysql\nDB_DATABASE=studio\n' > "$SANDBOX/opt/studio/shared/.env"
git clone -q --branch v1.0.0 "file://$REMOTE" "$SANDBOX/opt/studio/releases/v1.0.0" 2>/dev/null
git -C "$SANDBOX/opt/studio/releases/v1.0.0" rev-parse HEAD > "$SANDBOX/opt/studio/releases/v1.0.0/.studio-commit"
rm -rf "$SANDBOX/opt/studio/releases/v1.0.0/.git"
ln -s releases/v1.0.0 "$SANDBOX/opt/studio/current"

# ----------------------------------------------------------- stable channel ---

out=$(update check)
[ "$(jq -r .target <<<"$out")" = v1.1.0 ] && [ "$(jq -r .update_available <<<"$out")" = true ] && [ "$(jq -r .channel <<<"$out")" = stable ] || fail "check: $out"
ok "check: the stable channel offers v1.1.0 and ignores the pre-release"

reset_calls
update apply >/dev/null || fail "apply v1.1.0"
[ "$(current)" = v1.1.0 ] || fail "current is $(current)"
[ "$(state .status)" = ok ] && [ "$(state .previous)" = v1.0.0 ] && [ "$(state .update_available)" = false ] || fail "state $(cat "$SANDBOX/var/lib/studio-update/state.json")"
calls | grep -q 'ensure_system v1.1.0' || fail "no system step"
calls | grep -q 'upgrade 1.1.0 before FROM=v1.0.0 TO=v1.1.0' || fail "no before phase"
calls | grep -q 'upgrade 1.1.0 after FROM=v1.0.0 TO=v1.1.0' || fail "no after phase"
[ -n "$(state '.upgrades["1.1.0"] // empty')" ] || fail "upgrade 1.1.0 not recorded"
calls | grep -q 'composer install --no-dev' || fail "no composer install"
calls | grep -q 'mariadb-dump' || fail "no database dump"
order=$(calls | grep -E '^(artisan v1.0.0 down|artisan v1.1.0 migrate|install_release_files v1.1.0|artisan v1.1.0 up)' | cut -d' ' -f1-3 | tr '\n' '|')
[ "$order" = "artisan v1.0.0 down|artisan v1.1.0 migrate|install_release_files v1.1.0|artisan v1.1.0 up|" ] || fail "order: $order"
[ -L "$SANDBOX/opt/studio/releases/v1.1.0/.env" ] && [ -L "$SANDBOX/opt/studio/releases/v1.1.0/storage" ] || fail "shared .env/storage not linked"
[ -s "$SANDBOX/opt/studio/releases/v1.1.0/.studio-commit" ] || fail "no .studio-commit"
[ ! -d "$SANDBOX/opt/studio/releases/v1.1.0/node_modules" ] || fail "node_modules left behind"
ls "$SANDBOX/var/backups/studio"/studio-v1.0.0-to-v1.1.0-*.sql.gz >/dev/null || fail "no backup file"
[ "$(state '.log | length')" -gt 3 ] || fail "no log in the state"
ok "apply: v1.0.0 → v1.1.0 with system step, upgrade phases, dump, migrate, switch"

out=$(update apply) || fail "apply when up to date"
grep -q 'already the newest build' <<<"$out" || fail "up to date: $out"
ok "nothing to do on the newest build"

reset_calls
update apply --force >/dev/null || fail "force on the same release"
[ "$(current)" = v1.1.0 ] && [[ "$(current_dir)" == v1.1.0@* ]] || fail "force: current dir $(current_dir)"
[ "$(state .message)" = "Reinstalled v1.1.0." ] || fail "force message $(state .message)"
if calls | grep -q 'upgrade 1.1.0'; then fail "an unchanged upgrade script ran again on force"; fi
calls | grep -Eq 'artisan v1.1.0@[0-9]+ migrate' || fail "force did not migrate on the new build"
ok "force: the installed release is built and installed again, next to the live one"

release v1.2.0
touch "$SANDBOX/fail-migrate-v1.2.0"
reset_calls
if update apply >/dev/null 2>&1; then fail "apply v1.2.0 should fail"; fi
[ "$(current)" = v1.1.0 ] || fail "current after failed migrate: $(current)"
[ "$(state .status)" = failed ] || fail "status $(state .status)"
calls | grep -q 'DROP DATABASE IF EXISTS `studio`' || fail "database not restored"
calls | grep -Eq 'artisan v1.1.0@[0-9]+ up' || fail "maintenance not lifted"
ok "failed migrations: v1.1.0 stays, the database dump comes back, maintenance ends"

rm -f "$SANDBOX/fail-migrate-v1.2.0"
release v1.2.1
touch "$SANDBOX/fail-health"
reset_calls
if update apply >/dev/null 2>&1; then fail "apply v1.2.1 should fail"; fi
rm -f "$SANDBOX/fail-health"
[ "$(current)" = v1.1.0 ] || fail "current after failed health: $(current)"
calls | grep -q 'install_release_files v1.1.0@' || fail "old root files not reinstalled"
calls | grep -q 'DROP DATABASE' || fail "database not restored after switch"
grep -q 'health check' <<<"$(state '.message')" || fail "message $(state .message)"
ok "failed health check after the switch: back to v1.1.0 with its files and database"

release v1.2.2 1.2.1
if update apply >/dev/null 2>&1; then fail "a VERSION mismatch should fail"; fi
[ ! -d "$SANDBOX/opt/studio/releases/v1.2.2" ] && [ ! -d "$SANDBOX/opt/studio/releases/.incoming" ] || fail "mismatched release left on disk"
ok "a tag whose VERSION says otherwise is refused"

release v1.2.3
ssh-keygen -q -t ed25519 -N '' -C release@studio.test -f "$SANDBOX/release-key"
echo "release@studio.test $(cut -d' ' -f1-2 "$SANDBOX/release-key.pub")" > "$SANDBOX/etc/studio/update-signers"
if update apply >/dev/null 2>&1; then fail "an unsigned tag should be refused"; fi
grep -q signature <<<"$(state .message)" || fail "message $(state .message)"
ok "with update-signers, an unsigned tag is refused"

commit 1.2.4 v1.2.4
git -C "$REMOTE" -c gpg.format=ssh -c user.signingkey="$SANDBOX/release-key" tag -s v1.2.4 -m v1.2.4
update apply >/dev/null || fail "a signed tag should be accepted: $(state .message)"
[ "$(current)" = v1.2.4 ] || fail "current $(current)"
grep -q 'signature of v1.2.4 verified' "$SANDBOX/var/log/studio-update.log" || fail "signature not reported"
ok "a tag signed by an allowed key is installed"
rm -f "$SANDBOX/etc/studio/update-signers"

reset_calls
update auto off >/dev/null
calls | grep -q 'systemctl disable --now studio-update-auto.timer' || fail "timer not disabled"
grep -q '^AUTO_UPDATE=0' "$SANDBOX/etc/studio/studio.env" || fail "AUTO_UPDATE not written"
release v1.2.5
out=$(update apply --auto)
grep -q 'automatic updates are off' <<<"$out" || fail "auto off: $out"
[ "$(current)" = v1.2.4 ] || fail "auto off still updated"
[ "$(state .target)" = v1.2.5 ] && [ "$(state .update_available)" = true ] || fail "auto off did not record the check"
update auto on >/dev/null
ok "automatic updates off: the nightly run only checks"

update apply >/dev/null || fail "apply v1.2.5"
[ "$(current)" = v1.2.5 ] || fail "current $(current)"

# ------------------------------------------------------------- beta channel ---

upgrade_script 1.3.0
commit 1.3.0 "beta work"
main=$(git -C "$REMOTE" rev-parse --short=7 HEAD)
out=$(update channel beta)
[ "$(jq -r .channel <<<"$out")" = beta ] && grep -q '^UPDATE_CHANNEL=beta' "$SANDBOX/etc/studio/studio.env" || fail "channel: $out"
[ "$(state .target)" = "main-$main" ] && [ "$(state .update_available)" = true ] || fail "beta target $(state .target)"
reset_calls
update apply >/dev/null || fail "apply on beta"
[ "$(current)" = "main-$main" ] || fail "beta current $(current)"
calls | grep -q 'upgrade 1.3.0 after FROM=v1.2.5 TO=main-' || fail "the 1.3.0 script did not run on the beta build"
ok "beta: the head of main is installed, with the upgrade scripts of its version"

out=$(update apply)
grep -q 'already the newest build of the beta channel' <<<"$out" || fail "beta up to date: $out"
upgrade_script 1.3.0 2
commit 1.3.0 "beta work, the upgrade script changed"
reset_calls
update apply >/dev/null || fail "apply the next beta build"
calls | grep -q 'upgrade 1.3.0 after .* body=2' || fail "a changed upgrade script of the same version did not run again"
commit 1.3.0 "more beta work"
reset_calls
update apply >/dev/null || fail "apply another beta build"
if calls | grep -q 'upgrade 1.3.0'; then fail "an unchanged upgrade script ran again"; fi
ok "beta: every new commit is installed; a changed upgrade script of the same version runs again"

echo "release@studio.test $(cut -d' ' -f1-2 "$SANDBOX/release-key.pub")" > "$SANDBOX/etc/studio/update-signers"
commit 1.3.0 "unsigned beta work"
if update apply >/dev/null 2>&1; then fail "an unsigned main commit should be refused with update-signers"; fi
grep -q 'not signed' <<<"$(state .message)" || fail "message $(state .message)"
rm -f "$SANDBOX/etc/studio/update-signers"
ok "beta with update-signers: an unsigned commit on main is refused"

update apply >/dev/null || fail "apply the unsigned beta build without signers"
beta=$(current)
update channel stable >/dev/null
[ "$(state .update_available)" = false ] && grep -q 'newer than v1.2.5' <<<"$(state .waiting)" || fail "stable after beta: $(state .waiting)"
out=$(update apply)
[ "$(current)" = "$beta" ] && grep -q 'newer than' <<<"$out" || fail "stable went back to a release: $(current)"
if update apply --force >/dev/null 2>&1; then fail "force must not go back to an older release"; fi
[ "$(current)" = "$beta" ] || fail "force went back: $(current)"
ok "back on stable, the main build stays (even with force) until a release contains it"

git -C "$REMOTE" tag v1.3.0
reset_calls
update apply >/dev/null || fail "apply v1.3.0 after beta"
[ "$(current)" = v1.3.0 ] || fail "current $(current)"
if calls | grep -q 'upgrade 1.3.0'; then fail "the 1.3.0 script ran again though beta had run it unchanged"; fi
ok "the first release that contains the beta build is installed, without repeating its upgrade scripts"

out=$(update apply --to v1.1.0 2>&1) && fail "a downgrade with apply should be refused"
grep -q 'rollback' <<<"$out" || fail "downgrade message: $out"
ok "apply never goes backwards"

dirs=$(find "$SANDBOX/opt/studio/releases" -mindepth 1 -maxdepth 1 -type d ! -name '.*' | wc -l | tr -d ' ')
[ "$dirs" -le 3 ] || fail "$dirs releases kept: $(ls "$SANDBOX/opt/studio/releases" | tr '\n' ' ')"
ok "at most three releases stay on disk"

prev=$(state .previous)
reset_calls
update rollback >/dev/null || fail "rollback"
[ "$(current)" = "$prev" ] || fail "rollback current $(current), expected $prev"
calls | grep -q 'DROP DATABASE' || fail "rollback did not restore the database"
[ "$(state .status)" = ok ] || fail "rollback status"
ok "rollback: back to the previous release and its database"

printf '\n%s checks passed\n' "$pass"

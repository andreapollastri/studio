#!/usr/bin/env bash
#
# Studio — installer for a fresh Ubuntu 24.04 / 26.04 LTS server.
#
#   wget -qO- https://raw.githubusercontent.com/andreapollastri/studio/refs/heads/main/installer/install.sh | sudo bash
#
# What it does, in order:
#   1. asks for the domain and shows the DNS record to create (*.<domain> → this server)
#   2. checks that the wildcard resolves here
#   3. asks for the first administrator (name, email); the password is generated and
#      shown in the summary, before anything is installed, and again at the end
#   4. asks for the git provider of this Studio (GitHub, GitLab, Bitbucket or Azure
#      DevOps: the same ones Larapilot integrates with) and its address
#   5. asks for that administrator's token there, with instructions, and verifies it
#   6. installs the newest release of Studio, the latest published vX.Y.Z tag (the
#      head of main only while no release is published), in /opt/studio/releases/<tag>,
#      with what it needs: Caddy, PHP, Composer, Node, Claude Code, the provider's CLI,
#      MariaDB, PostgreSQL, redis
#
# Beta testers install the head of main instead, and keep following it:
#   wget -qO- https://raw.githubusercontent.com/andreapollastri/studio/refs/heads/main/installer/install.sh | sudo STUDIO_CHANNEL=beta bash
#
# Re-running it on an installed server runs the updater (studio-update apply).
# Every prompt reads from the terminal, so piping the script into bash is fine.
#
set -Eeuo pipefail

# A command that fails stops the installer (set -e); say so, and where, instead of
# going quiet. -E above carries the trap into the functions of installer/lib/system.sh.
trap 'printf "\n\033[1;31mERROR: the installer stopped at %s:%s\n  command: %s\n  apt, NodeSource and npm wrote to %s; its last lines may say why.\n  Fix the cause and run the installer again: it picks up where things are.\033[0m\n" "${BASH_SOURCE[0]##*/}" "$LINENO" "$BASH_COMMAND" "${STUDIO_APT_LOG:-/var/log/studio-install.log}" >&2' ERR

STUDIO_REPO=${STUDIO_REPO:-https://github.com/andreapollastri/studio.git}
STUDIO_REF=${STUDIO_REF:-}          # a release tag (vX.Y.Z) to install instead of the newest one
STUDIO_CHANNEL=${STUDIO_CHANNEL:-stable}   # stable: release tags · beta: the main branch (beta testers)
STUDIO_HOME=/opt/studio
STUDIO_ETC=/etc/studio
export STUDIO_HOME STUDIO_ETC

# ---------------------------------------------------------------- helpers ---

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

ask() { # ask VAR "Question" [default]
    local var=$1 q=$2 def=${3:-} ans
    if [ -n "$def" ]; then printf '%s [%s]: ' "$q" "$def"; else printf '%s: ' "$q"; fi
    read -r ans < /dev/tty || true
    printf -v "$var" '%s' "${ans:-$def}"
}

ask_secret() { # ask_secret VAR "Question"
    local var=$1 q=$2 ans
    printf '%s: ' "$q"
    read -rs ans < /dev/tty || true
    printf '\n'
    printf -v "$var" '%s' "$ans"
}

confirm() { # confirm "Question" -> 0 yes
    local ans
    printf '%s [Y/n]: ' "$1"
    read -r ans < /dev/tty || true
    [[ -z "$ans" || "$ans" =~ ^[Yy] ]]
}

random_hex() { openssl rand -hex "${1:-16}"; }

# twenty letters and digits: easy to copy, nothing a terminal or a keyboard layout mangles
generate_password() { local p; p=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9'); printf '%s' "${p:0:20}"; }

banner() { # banner LINE… — black on yellow, every line as wide as the longest
    local l w=0
    for l in "$@"; do if [ "${#l}" -gt "$w" ]; then w=${#l}; fi; done
    printf '\n'
    printf '    \033[1;30;43m  %-*s  \033[0m\n' "$w" ""
    for l in "$@"; do printf '    \033[1;30;43m  %-*s  \033[0m\n' "$w" "$l"; done
    printf '    \033[1;30;43m  %-*s  \033[0m\n' "$w" ""
    printf '\n'
}

credentials_banner() {
    banner "SAVE THESE DETAILS NOW. The password is generated for you and shown only here" \
        "and at the end of the installation: nobody can read it back later." \
        "" \
        "Dashboard:  https://studio.${DOMAIN}" \
        "Email:      ${ADMIN_EMAIL}" \
        "Password:   ${ADMIN_PASSWORD}" \
        "" \
        "Change it after the first sign-in in Settings > Security, and turn on two-factor."
}

public_ip() {
    curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null \
        || curl -4 -fsS --max-time 5 https://ifconfig.me/ip 2>/dev/null \
        || hostname -I | awk '{print $1}'
}

resolve() { # resolve HOST -> first IPv4 via a public resolver (dig) or the system
    if command -v dig >/dev/null 2>&1; then
        dig +short +time=3 +tries=1 @1.1.1.1 A "$1" 2>/dev/null | grep -E '^[0-9.]+$' | head -n1
    else
        getent ahostsv4 "$1" 2>/dev/null | awk '{print $1; exit}'
    fi
}

# who_is TOKEN -> the login the token belongs to on the chosen provider, or nothing
who_is() {
    local t=$1
    case "$GIT_PROVIDER" in
        github) curl -fsS --max-time 10 -H "Authorization: Bearer $t" -H 'Accept: application/vnd.github+json' https://api.github.com/user | jq -r '.login // empty' ;;
        gitlab) curl -fsS --max-time 10 -H "PRIVATE-TOKEN: $t" "$API_BASE/api/v4/user" | jq -r '.username // empty' ;;
        bitbucket) curl -fsS --max-time 10 -H "Authorization: Bearer $t" https://api.bitbucket.org/2.0/user | jq -r '.username // .nickname // empty' ;;
        azure) curl -fsS --max-time 10 -u ":$t" "$API_BASE/_apis/connectionData" | jq -r '.authenticatedUser.properties.Account["$value"] // empty' ;;
    esac 2>/dev/null || true
}

token_help() {
    case "$GIT_PROVIDER" in
        github)
            note "Create a fine-grained personal access token:"
            note "  GitHub → Settings → Developer settings → Personal access tokens → Fine-grained → Generate"
            note "  Resource owner: the organization of your projects (it may have to approve the token)"
            note "  Repository access: the repositories of your projects, or all of them"
            note "  Permissions: Contents, Pull requests, Administration, Webhooks (read & write) · Metadata (read)"
            note "A classic token with  repo, read:org, admin:repo_hook  works too." ;;
        gitlab)
            note "Create a personal access token on $API_BASE:"
            note "  avatar → Edit profile → Access tokens → Add new token"
            note "  Scope: api   ·   Expiration: GitLab asks for a date; renew it in Studio before it expires" ;;
        bitbucket)
            note "Create an Atlassian API token with scopes (app passwords are retired):"
            note "  id.atlassian.com → Account settings → Security → Create and manage API tokens"
            note "  → Create API token with scopes → Bitbucket, with these scopes:"
            note "  read:user, read:workspace, read:project, read:repository, write:repository, admin:repository,"
            note "  read:pullrequest, write:pullrequest, read:webhook, write:webhook, delete:webhook" ;;
        azure)
            note "Create a personal access token in the organization $GIT_ORG:"
            note "  $API_BASE → User settings → Personal access tokens → New Token"
            note "  Scopes (Custom defined): Code → Read, write & manage · Project and Team → Read"
            note "  Registering the push service hooks also needs the Project Administrator role." ;;
    esac
}

# ---------------------------------------------------------------- preflight --

[ "$(id -u)" = 0 ] || die "run as root:  sudo bash install.sh"
. /etc/os-release
case "${ID:-}:${VERSION_ID:-}" in
    ubuntu:24.04|ubuntu:26.04) ;;
    *) die "Ubuntu 24.04 or 26.04 LTS is required (this is ${PRETTY_NAME:-unknown})." ;;
esac
case "$(dpkg --print-architecture)" in amd64|arm64) ;; *) die "amd64 or arm64 only." ;; esac

# Installed means the install went to the end: Studio is live and the updater is in place.
if [ -L "$STUDIO_HOME/current" ] && [ -x /usr/local/sbin/studio-update ] && [ -f "$STUDIO_ETC/studio.env" ]; then
    printf '\n\033[1m  Studio\033[0m is installed here: updating it to the newest build of its channel.\n'
    exec /usr/local/sbin/studio-update apply
fi
if [ -f "$STUDIO_ETC/studio.env" ]; then
    warn "an earlier installation stopped before the end: starting it again from the questions"
    rm -f "$STUDIO_ETC/studio.env"
fi

# apt's own output goes to a log; on failure its last lines are shown
STUDIO_APT_LOG=/var/log/studio-install.log
export STUDIO_APT_LOG
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1
APT=(apt-get -y -qq -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef)
apt_run() {
    "${APT[@]}" "$@" >>"$STUDIO_APT_LOG" 2>&1 && return 0
    warn "apt-get $* failed; the end of $STUDIO_APT_LOG:"
    tail -n 25 "$STUDIO_APT_LOG" | sed 's/^/      /' >&2
    die "the packages could not be installed"
}
# a PHP PPA source left by an earlier run, for a Ubuntu release the PPA does not serve, breaks apt
if compgen -G '/etc/apt/sources.list.d/*ondrej*' >/dev/null \
    && ! curl -fsI --max-time 15 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${VERSION_CODENAME:-}/Release" >/dev/null 2>&1; then
    rm -f /etc/apt/sources.list.d/*ondrej*
fi
apt_run update
apt_run install curl git jq openssl dnsutils ca-certificates

printf '\n\033[1m  Studio\033[0m — one server for a team of Laravel workspaces\n'
printf '  Ubuntu %s · %s\n' "$VERSION_ID" "$(dpkg --print-architecture)"

# ---------------------------------------------------------------- wizard -----

say "1/5 · The domain"
note "Every host of Studio hangs under one domain:"
note "  studio.<domain>             the dashboard"
note "  <project>.<domain>          the deploy branch of a project"
note "  <user>-<project>.<domain>   one person's workspace preview"
while :; do
    ask DOMAIN "Domain (for example dev.example.com)"
    [[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]] && break
    warn "that does not look like a domain"
done

SERVER_IP=$(public_ip)
say "2/5 · DNS"
note "Create this record at your DNS provider, then come back here:"
printf '\n      \033[1m*.%s    A    %s\033[0m\n\n' "$DOMAIN" "$SERVER_IP"
note "(a wildcard covers every project and preview; nothing else is needed)"
while :; do
    printf 'Press Enter to check the DNS, or type  s  to skip the check: '
    read -r ans < /dev/tty || true
    [ "$ans" = s ] && { warn "skipping the DNS check: certificates will fail until the wildcard points here"; break; }
    probe="probe-$(random_hex 3).$DOMAIN"
    got_probe=$(resolve "$probe" || true)
    got_studio=$(resolve "studio.$DOMAIN" || true)
    if [ "$got_probe" = "$SERVER_IP" ] && [ "$got_studio" = "$SERVER_IP" ]; then
        note "✓ $probe and studio.$DOMAIN resolve to $SERVER_IP"
        break
    fi
    warn "not yet: $probe → ${got_probe:-nothing}, studio.$DOMAIN → ${got_studio:-nothing} (expected $SERVER_IP). DNS can take a few minutes."
done

say "3/5 · The first administrator"
choice=""   # set by ask
ask ADMIN_NAME "Name"
while :; do
    ask ADMIN_EMAIL "Email"
    [[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ ]] && break
    warn "that does not look like an email"
done
ADMIN_PASSWORD=$(generate_password)
note "The password of this account is generated: the summary below shows it, save it."

say "4/5 · The git provider"
note "Studio works with ONE git provider, chosen now: every project is a repository there and"
note "every person connects a token for it. The same forges Larapilot integrates with:"
note "  1) GitHub      2) GitLab (gitlab.com or self-managed)      3) Bitbucket Cloud      4) Azure DevOps"
# GIT_URL goes into Studio's .env (empty = the provider's default); API_BASE is where the token is checked
GIT_URL=""; API_BASE=""; GIT_ORG=""
while :; do
    ask choice "Provider" 1
    case "$choice" in
        1|github) GIT_PROVIDER=github; GIT_LABEL=GitHub; break ;;
        2|gitlab) GIT_PROVIDER=gitlab; GIT_LABEL=GitLab; break ;;
        3|bitbucket) GIT_PROVIDER=bitbucket; GIT_LABEL=Bitbucket; break ;;
        4|azure) GIT_PROVIDER=azure; GIT_LABEL="Azure DevOps"; break ;;
        *) warn "1, 2, 3 or 4" ;;
    esac
done
if [ "$GIT_PROVIDER" = gitlab ]; then
    while :; do
        ask API_BASE "GitLab address" "https://gitlab.com"
        API_BASE=${API_BASE%/}
        [[ "$API_BASE" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[^[:space:]]*)?$ ]] && break
        warn "an https:// address, like https://gitlab.com or https://git.example.com"
    done
    [ "$API_BASE" = "https://gitlab.com" ] || GIT_URL=$API_BASE
fi
if [ "$GIT_PROVIDER" = azure ]; then
    note "One Studio works with one Azure DevOps organization: https://dev.azure.com/<organization>"
    while :; do
        ask GIT_ORG "Organization"
        GIT_ORG=${GIT_ORG#https://dev.azure.com/}; GIT_ORG=${GIT_ORG%%/*}
        [[ "$GIT_ORG" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,49}$ ]] && break
        warn "the organization name, as in https://dev.azure.com/<organization>"
    done
    API_BASE="https://dev.azure.com/$GIT_ORG"
    GIT_URL=$API_BASE
fi

say "5/5 · Your $GIT_LABEL token"
note "Studio clones and deploys project sites with the administrator's token, and creates"
note "new repositories with it; every person later adds their own in Settings → Connections."
token_help
GIT_LOGIN=""
while :; do
    ask_secret GIT_TOKEN "$GIT_LABEL token (empty = add it later from the dashboard)"
    [ -z "$GIT_TOKEN" ] && break
    GIT_LOGIN=$(who_is "$GIT_TOKEN")
    if [ -n "$GIT_LOGIN" ]; then note "✓ the token belongs to $GIT_LOGIN"; break; fi
    warn "$GIT_LABEL did not accept that token"
done

# The build: always the newest published release (the highest vX.Y.Z tag); the head of
# main only while no release exists, or for beta testers (STUDIO_CHANNEL=beta)
[ "$STUDIO_CHANNEL" = beta ] || STUDIO_CHANNEL=stable
if [ -n "$STUDIO_REF" ] && [[ ! "$STUDIO_REF" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    die "STUDIO_REF must be a release tag like v1.0.0 (for the main branch use STUDIO_CHANNEL=beta)"
fi
if [ "$STUDIO_CHANNEL" = beta ]; then STUDIO_REF=main; fi
if [ -z "$STUDIO_REF" ]; then
    STUDIO_REF=$(git ls-remote --tags --refs "$STUDIO_REPO" 'v*' 2>/dev/null | awk '{print $2}' | sed 's#^refs/tags/##' \
        | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -n1 || true)
fi
MAIN_SHORT=$(git ls-remote "$STUDIO_REPO" refs/heads/main 2>/dev/null | cut -c1-7 || true)
if [ -z "$STUDIO_REF" ]; then
    warn "no release of Studio is published yet: installing the head of main; the first release replaces it"
    STUDIO_REF=main
fi

printf '\n'
note "Domain:        $DOMAIN  (server $SERVER_IP)"
note "Dashboard:     https://studio.$DOMAIN"
note "Administrator: $ADMIN_NAME <$ADMIN_EMAIL>"
note "Git provider:  $GIT_LABEL${API_BASE:+ ($API_BASE)}${GIT_LOGIN:+ · $GIT_LOGIN}"
if [ "$STUDIO_CHANNEL" = beta ]; then
    note "Studio:        main-${MAIN_SHORT} (beta channel), updated every night to the newest commit of main"
elif [ "$STUDIO_REF" = main ]; then
    note "Studio:        main-${MAIN_SHORT}, because no release is published yet; the first release replaces it"
else
    note "Studio:        ${STUDIO_REF}, the newest release; updated every night to each new release"
fi
credentials_banner
confirm "Saved them? Install now?" || die "aborted"

# ---------------------------------------------------------------- install ----

say "Packages"
note "Updating Ubuntu (the details go to $STUDIO_APT_LOG)…"
apt_run upgrade

mkdir -p "$STUDIO_HOME/releases" "$STUDIO_ETC"
INCOMING="$STUDIO_HOME/releases/.incoming"
rm -rf "$INCOMING"
git clone -q -c advice.detachedHead=false --depth 1 --branch "$STUDIO_REF" "$STUDIO_REPO" "$INCOMING" 2>/dev/null \
    || die "could not clone $STUDIO_REF from $STUDIO_REPO"
STUDIO_COMMIT=$(git -C "$INCOMING" rev-parse HEAD)
if [ "$STUDIO_REF" = main ]; then RELEASE_NAME="main-${STUDIO_COMMIT:0:7}"; else RELEASE_NAME=$STUDIO_REF; fi
echo "$STUDIO_COMMIT" > "$INCOMING/.studio-commit"   # what studio-update compares the next builds with
rm -rf "$INCOMING/.git"
RELEASE="$STUDIO_HOME/releases/$RELEASE_NAME"
rm -rf "$RELEASE"; mv "$INCOMING" "$RELEASE"

cat > "$STUDIO_ETC/studio.env" <<EOF
# Studio — written by the installer, read by studio-admin and studio-update. Root only.
DOMAIN=${DOMAIN}
ACME_EMAIL=${ADMIN_EMAIL}
STUDIO_HOME=${STUDIO_HOME}
STUDIO_DIR=${STUDIO_HOME}/current
STUDIO_PHP=8.4
STUDIO_REPO=${STUDIO_REPO}
AUTO_UPDATE=1
UPDATE_CHANNEL=${STUDIO_CHANNEL}
EOF
chmod 600 "$STUDIO_ETC/studio.env"

# The system this release needs, from the release itself (the updater does the same on every update)
# shellcheck disable=SC1091
. "$RELEASE/installer/lib/system.sh"
GIT_PROVIDER=$GIT_PROVIDER ensure_system "$RELEASE"

say "Studio $RELEASE_NAME"
STUDIO_DB_PASSWORD=$(random_hex 16)
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'studio'@'localhost' IDENTIFIED BY '${STUDIO_DB_PASSWORD}';
ALTER USER 'studio'@'localhost' IDENTIFIED BY '${STUDIO_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON studio.* TO 'studio'@'localhost';
FLUSH PRIVILEGES;
SQL

sed -e "s|__DOMAIN__|${DOMAIN}|g" \
    -e "s|__DB_PASSWORD__|${STUDIO_DB_PASSWORD}|g" \
    -e "s|__REVERB_APP_ID__|$(shuf -i 100000-999999 -n1)|g" \
    -e "s|__REVERB_APP_KEY__|$(random_hex 10)|g" \
    -e "s|__REVERB_APP_SECRET__|$(random_hex 10)|g" \
    -e "s|__GIT_PROVIDER__|${GIT_PROVIDER}|g" \
    -e "s|__GIT_URL__|${GIT_URL}|g" \
    -e "s|__PHP_VERSIONS__|$(installed_php_versions)|g" \
    "$RELEASE/installer/templates/studio.env.tpl" > "$STUDIO_HOME/shared/.env"
chown studio:studio "$STUDIO_HOME/shared/.env"; chmod 640 "$STUDIO_HOME/shared/.env"

ln -sfn "$STUDIO_HOME/shared/.env" "$RELEASE/.env"
rm -rf "$RELEASE/storage"; ln -sfn "$STUDIO_HOME/shared/storage" "$RELEASE/storage"
chown -R studio:studio "$RELEASE"; chmod 751 "$RELEASE"

as_studio() { sudo -u studio env HOME=/var/lib/studio COMPOSER_HOME=/var/lib/studio/.composer "$@"; }
cd "$RELEASE"
as_studio "php${STUDIO_PHP}" /usr/local/bin/composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader --quiet
as_studio npm ci --no-audit --no-fund --silent
as_studio npm run build --silent
rm -rf "$RELEASE/node_modules"
as_studio "php${STUDIO_PHP}" artisan key:generate --force --quiet
as_studio "php${STUDIO_PHP}" artisan migrate --force --quiet
as_studio "php${STUDIO_PHP}" artisan storage:link --force --quiet >/dev/null 2>&1 || true
as_studio "php${STUDIO_PHP}" artisan studio:make-admin --name="$ADMIN_NAME" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" ${GIT_TOKEN:+--git-token="$GIT_TOKEN"} >/dev/null

say "Services"
install_release_files "$RELEASE"
ln -sfn "releases/$RELEASE_NAME" "$STUDIO_HOME/current"
as_studio "php${STUDIO_PHP}" "$STUDIO_HOME/current/artisan" optimize --quiet
restart_studio
systemctl enable --now studio-update-auto.timer >/dev/null
# The updater's state: this build, its channel, and the upgrade scripts this release already
# covers (a fresh install has everything an upgrade script up to its version would do)
upgrades='{}'
for f in "$RELEASE"/installer/upgrades/*.sh; do
    [ -f "$f" ] || continue
    v=$(basename "$f" .sh)
    [[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || continue
    [ "$(printf '%s\n%s\n' "$v" "$(tr -d '[:space:]' < "$RELEASE/VERSION")" | sort -V | tail -n1)" = "$(tr -d '[:space:]' < "$RELEASE/VERSION")" ] || continue
    upgrades=$(jq --arg v "$v" --arg h "$(git hash-object "$f")" '. + {($v): $h}' <<<"$upgrades")
done
jq -n --arg c "$RELEASE_NAME" --arg cc "$STUDIO_COMMIT" --arg ch "$STUDIO_CHANNEL" --arg t "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson u "$upgrades" \
    '{current: $c, current_commit: $cc, current_dir: $c, channel: $ch, status: "installed", message: "Installed.", finished_at: $t, auto: true, upgrades: $u}' > /var/lib/studio-update/state.json
chmod 644 /var/lib/studio-update/state.json

# ---------------------------------------------------------------- done -------

printf '\n\033[1;32m  Studio %s is installed.\033[0m\n' "$RELEASE_NAME"
credentials_banner
note "Next: sign in, turn on two-factor in Settings → Security, add your Claude credential in"
note "Settings → Connections, then create the first project from Administration → Projects."
note "Updates: every night to the newest release (Administration → System, or  sudo studio-update apply)."
printf '\n'

# shellcheck shell=bash
#
# Studio — the system a release needs, as idempotent steps run as root.
#
# Sourced by installer/install.sh (first install) and by studio-update (every
# update, from the NEW release, before it goes live), so a release can bring a
# new PHP, a newer Node, another package or a changed service with it:
#
#   ensure_system RELEASE_DIR      packages, PHP, Composer, Node, Claude Code, the
#                                  Cursor CLI, the provider's CLI, databases, users and directories
#   install_release_files RELEASE_DIR
#                                  the root helper, the updater, templates, sudoers,
#                                  Studio's services, its PHP-FPM pool and the Caddyfile
#   upgrade_system RELEASE_DIR CHANGES_FILE
#                                  every package and tool on the server to its newest
#                                  version (studio-update system, nightly)
#   check_certificates OUT_FILE    every TLS certificate Caddy keeps: renew the late
#                                  ones, report what is still late (same run)
#
# Expects /etc/studio/studio.env to exist (DOMAIN, ACME_EMAIL, STUDIO_HOME, STUDIO_DIR…).
# Nothing here removes software: a rollback leaves new packages in place.

STUDIO_ETC=${STUDIO_ETC:-/etc/studio}
STUDIO_HOME=${STUDIO_HOME:-/opt/studio}
# bash 5.2 expands `&` in ${var//pattern/replacement}: template values must stay literal
shopt -u patsub_replacement 2>/dev/null || true

sys_say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
sys_note() { printf '    %s\n' "$*"; }
sys_warn() { printf '\033[1;33m    ! %s\033[0m\n' "$*"; }

# apt's own output goes to a log; on failure its last lines are shown
STUDIO_APT_LOG=${STUDIO_APT_LOG:-/var/log/studio-install.log}
# Chromium for the agents' browser (Playwright MCP), shared read-only by every workspace
STUDIO_BROWSERS=${STUDIO_BROWSERS:-/usr/local/lib/studio/browsers}

studio_apt() {
    if ! DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 \
        apt-get -y -qq -o DPkg::Lock::Timeout=900 -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef "$@" >>"$STUDIO_APT_LOG" 2>&1; then
        sys_warn "apt-get $* failed; the end of $STUDIO_APT_LOG:"
        tail -n 25 "$STUDIO_APT_LOG" | sed 's/^/      /' >&2
        return 1
    fi
}

# pkg_missing PKG... -> prints the packages that are not installed yet
pkg_missing() {
    local p
    for p in "$@"; do dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'install ok installed' || printf '%s\n' "$p"; done
}

apt_ensure() { # apt_ensure PKG... — install only what is missing, refreshing the index once
    local missing
    mapfile -t missing < <(pkg_missing "$@")
    [ "${#missing[@]}" -eq 0 ] && return 0
    [ "${APT_UPDATED:-0}" = 1 ] || { studio_apt update; APT_UPDATED=1; }
    studio_apt install "${missing[@]}"
}

# env_get FILE KEY -> the value, without quotes
env_get() { [ -f "$1" ] && sed -n "s/^$2=//p" "$1" | tail -n1 | sed 's/^"\(.*\)"$/\1/' || true; }

# env_set FILE KEY VALUE — replace or append one line
env_set() {
    local f=$1 k=$2 v=$3
    if grep -qE "^${k}=" "$f" 2>/dev/null; then
        local tmp; tmp=$(mktemp)
        awk -v k="$k" -v v="$v" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' "$f" > "$tmp" && cat "$tmp" > "$f" && rm -f "$tmp"
    else
        printf '%s=%s\n' "$k" "$v" >> "$f"
    fi
}

# install_file SRC DEST MODE — through a temporary file and a rename, so a running
# script (the updater replacing itself) keeps reading its old copy
install_file() {
    local src=$1 dest=$2 mode=$3 tmp
    tmp=$(mktemp "$(dirname "$dest")/.studio.XXXXXX")
    cat "$src" > "$tmp"
    chmod "$mode" "$tmp"
    mv -f "$tmp" "$dest"
}

load_requirements() { # load_requirements RELEASE_DIR
    STUDIO_PHP=8.5; PHP_VERSIONS="8.5"; PHP_OPTIONAL=""; PHP_FIXED=""; NODE_MAJOR=22
    # shellcheck disable=SC1091
    [ -f "$1/installer/requirements.env" ] && . "$1/installer/requirements.env"
    resolve_studio_php
    export STUDIO_PHP PHP_VERSIONS PHP_OPTIONAL PHP_FIXED NODE_MAJOR
}

# The PHP that runs Studio: the release's choice when it is installed, otherwise the
# newest PHP on the server (Ubuntu 26.04, for one, has only the PHP it ships).
resolve_studio_php() {
    local best
    [ -x "/usr/bin/php${STUDIO_PHP}" ] && return 0
    best=$(installed_php_versions | tr ',' '\n' | sort -V | tail -n1)
    [ -n "$best" ] && STUDIO_PHP=$best
    return 0
}

# ver_ge A B: version A is B or later
ver_ge() { [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$2" ]; }

# ------------------------------------------------------------------ packages

ensure_base_packages() {
    apt_ensure curl wget git unzip zip python3 jq acl ufw fail2ban cron openssl ca-certificates gnupg lsb-release \
        software-properties-common unattended-upgrades debian-keyring debian-archive-keyring apt-transport-https sudo dnsutils \
        openssh-client rclone sqlite3 mariadb-client postgresql-client
}

ensure_caddy() {
    command -v caddy >/dev/null 2>&1 && return 0
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>>"$STUDIO_APT_LOG"
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
    APT_UPDATED=0
    apt_ensure caddy
}

APT_SOURCES=${APT_SOURCES:-/etc/apt/sources.list.d}
SURY_KEYRING=${SURY_KEYRING:-/usr/share/keyrings/debsuryorg-archive-keyring.gpg}

# packages.sury.org and ppa:ondrej/php, when they publish packages for this Ubuntu release
sury_available() {
    curl -fsI --max-time 15 "https://packages.sury.org/php/dists/$1/Release" >/dev/null 2>&1
}
ondrej_available() {
    curl -fsI --max-time 15 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/$1/Release" >/dev/null 2>&1
}

# ensure_sury CODENAME — sury.org's keyring (a package of its own) and its source; 1, with
# no source written, when the keyring cannot be had
ensure_sury() {
    local line dir
    if [ ! -s "$SURY_KEYRING" ]; then
        dir=$(mktemp -d)
        if curl -fsSL --max-time 60 -o "$dir/debsuryorg-archive-keyring.deb" https://packages.sury.org/debsuryorg-archive-keyring.deb; then
            studio_apt install "$dir/debsuryorg-archive-keyring.deb" || true
        fi
        rm -rf "$dir"
        [ -s "$SURY_KEYRING" ] || return 1
    fi
    line="deb [signed-by=$SURY_KEYRING] https://packages.sury.org/php/ $1 main"
    [ "$(cat "$APT_SOURCES/php-sury.list" 2>/dev/null)" = "$line" ] && return 0
    printf '%s\n' "$line" > "$APT_SOURCES/php-sury.list"
    APT_UPDATED=0; PHP_SOURCE_MOVED=1
}

# php_source CODENAME — where PHP comes from, in PHP_SOURCE: packages.sury.org, then
# ppa:ondrej/php, then Ubuntu's archive (one PHP alone). sury.org and the PPA are the same
# maintainer and packages, every version side by side, but sury.org publishes first (on 21
# October 2026 it had the fixed builds while the PPA was still building them) and served
# Ubuntu 26.04 before the PPA did. The PPA goes only once sury.org is in place, so a failed
# keyring leaves the server as it was; a sury.org source already there for this release
# stays when sury.org does not answer. PHP_SOURCE_MOVED=1 when a source came or went.
php_source() {
    local codename=$1
    PHP_SOURCE_MOVED=${PHP_SOURCE_MOVED:-0}
    if { sury_available "$codename" || grep -qs " $codename main" "$APT_SOURCES/php-sury.list"; } && ensure_sury "$codename"; then
        PHP_SOURCE=packages.sury.org
        if compgen -G "$APT_SOURCES/*ondrej*" >/dev/null; then
            rm -f "$APT_SOURCES"/*ondrej*
            APT_UPDATED=0; PHP_SOURCE_MOVED=1
        fi
        return 0
    fi
    # a source left behind for a release a repository does not serve breaks every apt update
    if [ -f "$APT_SOURCES/php-sury.list" ]; then
        rm -f "$APT_SOURCES/php-sury.list"
        APT_UPDATED=0; PHP_SOURCE_MOVED=1
    fi
    if ondrej_available "$codename"; then
        PHP_SOURCE=ppa:ondrej/php
        if ! grep -rqs 'ondrej/php' "$APT_SOURCES"/; then
            add-apt-repository -y -n ppa:ondrej/php >>"$STUDIO_APT_LOG" 2>&1
            APT_UPDATED=0; PHP_SOURCE_MOVED=1
        fi
        return 0
    fi
    PHP_SOURCE=archive
    if compgen -G "$APT_SOURCES/*ondrej*" >/dev/null; then
        rm -f "$APT_SOURCES"/*ondrej*
        APT_UPDATED=0; PHP_SOURCE_MOVED=1
    fi
}

# Every version in PHP_VERSIONS this Ubuntu can install, and every one already here (an
# optional one an administrator added, or one an older release installed), with the
# extensions Laravel apps expect, from php_source's repository.
ensure_php() {
    local codename v pkgs avail=()
    codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
    PHP_SOURCE_MOVED=0
    php_source "$codename"
    if [ "$PHP_SOURCE" = archive ]; then
        sys_note "neither packages.sury.org nor ppa:ondrej/php serves Ubuntu ${codename:-this release} yet: PHP comes from Ubuntu's archive"
    else
        sys_note "PHP from $PHP_SOURCE"
    fi
    [ "${APT_UPDATED:-0}" = 1 ] || { studio_apt update; APT_UPDATED=1; }
    # moved to another repository: what is installed goes to its builds where they are newer
    # (Ubuntu's PHP 8.5 to sury.org's, with the security fixes), then what still runs the old PHP restarts
    if [ "$PHP_SOURCE_MOVED" = 1 ]; then
        mapfile -t pkgs < <(dpkg-query -W -f='${db:Status-Abbrev} ${binary:Package}\n' 'php*' 2>/dev/null | awk '$1 ~ /^ii/ {print $2}')
        if [ "${#pkgs[@]}" -gt 0 ]; then
            studio_apt install --only-upgrade "${pkgs[@]}" || sys_warn "PHP's packages could not move to $PHP_SOURCE"
            restart_outdated_services
        fi
    fi

    for v in $PHP_VERSIONS; do
        if apt-cache show "php${v}-fpm" >/dev/null 2>&1; then avail+=("$v"); else sys_note "PHP $v is not available on Ubuntu ${codename}: skipped"; fi
    done
    if [ "${#avail[@]}" -eq 0 ]; then
        # none of the versions the release names: the PHP this Ubuntu ships
        v=$(apt-cache depends php-fpm 2>/dev/null | grep -o 'php[0-9][0-9]*\.[0-9][0-9]*-fpm' | head -n1 | sed 's/^php//; s/-fpm$//')
        [ -n "$v" ] || { sys_warn "no PHP-FPM package is available on this Ubuntu"; return 1; }
        avail=("$v")
    fi
    for v in $(installed_php_versions | tr ',' ' '); do
        case " ${avail[*]} " in *" $v "*) ;; *) avail+=("$v") ;; esac
    done
    for v in "${avail[@]}"; do ensure_php_version "$v"; done

    # every PHP here, also one an older release installed and this one no longer names
    for v in $(installed_php_versions | tr ',' ' '); do ensure_php_fixed "$v"; done

    resolve_studio_php
    export STUDIO_PHP
    update-alternatives --set php "/usr/bin/php${STUDIO_PHP}" >/dev/null 2>&1 || true
}

# ensure_php_version V — PHP V's packages and extensions, Studio's settings, its PHP-FPM running
ensure_php_version() {
    local v=$1 e pkgs=()
    for e in cli fpm mysql pgsql mbstring xml curl zip gd intl bcmath readline sqlite3; do pkgs+=("php${v}-${e}"); done
    # OPcache is part of PHP itself from 8.5 on; before that it is a package of its own
    ver_ge "$v" 8.5 || pkgs+=("php${v}-opcache")
    apt_ensure "${pkgs[@]}"
    # redis: php8.x-redis from sury.org or the PPA, or Ubuntu's php-redis (which provides it there)
    if apt-cache show "php${v}-redis" >/dev/null 2>&1; then
        apt_ensure "php${v}-redis" || sys_warn "the redis extension could not be installed for PHP $v"
    elif apt-cache show php-redis >/dev/null 2>&1; then
        apt_ensure php-redis || sys_warn "the redis extension could not be installed for PHP $v"
    else
        sys_warn "the redis extension is not available for PHP $v"
    fi
    cat > "/etc/php/${v}/fpm/conf.d/99-studio.ini" <<'INI'
opcache.enable=1
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
realpath_cache_size=4096K
INI
    php_fpm_unconfined "$v"
    systemctl enable --now "php${v}-fpm" >/dev/null 2>&1
}

SYSTEMD_UNITS=${SYSTEMD_UNITS:-/etc/systemd/system}

# php_fpm_unconfined V — PHP-FPM's own unit (sury.org's, Debian's) has ProtectSystem=full: /etc
# and /usr read-only for the master and for everything a pool starts. Studio's pool runs its
# root helper from web requests (sudo studio-admin: a person's Linux user, Claude Code's policy
# in /etc/claude-code), which inherits that view and stops at "Read-only file system". Every
# pool runs as its own user, which the file permissions keep out of /etc anyway. A change of
# the drop-in restarts PHP-FPM: only a new master gets the new view.
php_fpm_unconfined() {
    local v=$1 dir="$SYSTEMD_UNITS/php$1-fpm.service.d" want
    want=$'[Service]\n# Studio: its pool runs the root helper (sudo studio-admin), which writes in /etc\nProtectSystem=false'
    [ "$(cat "$dir/studio.conf" 2>/dev/null)" = "$want" ] && return 0
    mkdir -p "$dir"
    printf '%s\n' "$want" > "$dir/studio.conf"
    systemctl daemon-reload >/dev/null 2>&1 || true
    if systemctl is-active --quiet "php${v}-fpm"; then
        systemctl restart "php${v}-fpm" >/dev/null 2>&1 || sys_warn "php${v}-fpm did not restart"
    fi
}

# add_php V — one more PHP among those the release offers (PHP_VERSIONS, PHP_OPTIONAL), from
# php_source's repository, on the fixes PHP_FIXED names; Studio's forms then offer it.
# Administration → System → PHP, through studio-admin php-install.
add_php() {
    local v=$1 codename
    case " $PHP_VERSIONS $PHP_OPTIONAL " in
        *" $v "*) ;;
        *) sys_warn "PHP $v is not one this release offers (${PHP_VERSIONS} ${PHP_OPTIONAL})"; return 1 ;;
    esac
    codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
    php_source "$codename"
    [ "${APT_UPDATED:-0}" = 1 ] || { studio_apt update; APT_UPDATED=1; }
    if ! apt-cache show "php${v}-fpm" >/dev/null 2>&1; then
        sys_warn "PHP $v is not available on Ubuntu ${codename:-this release} (PHP from ${PHP_SOURCE})"
        return 1
    fi
    sys_note "· PHP $v from ${PHP_SOURCE}"
    ensure_php_version "$v"
    [ -n "$(php_patch_level "$v")" ] || { sys_warn "PHP $v did not install"; return 1; }
    ensure_php_fixed "$v"
    [ -f "$STUDIO_HOME/shared/.env" ] && env_set "$STUDIO_HOME/shared/.env" STUDIO_PHP_VERSIONS "$(installed_php_versions)"
    return 0
}

# php_patch_level V -> the PHP of branch V installed here (8.4.27), empty without one
php_patch_level() { "/usr/bin/php$1" -r 'echo PHP_VERSION;' 2>/dev/null || true; }

# php_from_ubuntu V: PHP V comes from Ubuntu's archive (8.3.6-0ubuntu0.24.04.5, 8.5.4-1ubuntu1),
# which backports security fixes and keeps the version number it shipped. The builds of
# sury.org and of the PPA carry PHP's own (8.4.27-1+ubuntu24.04.1), not always with "sury" in it.
php_from_ubuntu() { dpkg-query -W -f='${Version}' "php$1-fpm" 2>/dev/null | grep -qE -- '-[0-9]+ubuntu[0-9]'; }

# ensure_php_fixed V — PHP V on the patch PHP_FIXED names for its branch or later: an
# apt update leaves an installed PHP where it is (apt_ensure installs only what is
# missing), so its packages go to their newest version here, and the services still
# on the old PHP restart, PHP-FPM first: no worker keeps state from before.
ensure_php_fixed() {
    local v=$1 min cur pkgs build
    min=$(printf '%s\n' $PHP_FIXED | grep -E "^${v//./\\.}\.[0-9]+$" | head -n1 || true)
    [ -n "$min" ] || return 0
    cur=$(php_patch_level "$v")
    ver_ge "${cur:-0}" "$min" && return 0
    mapfile -t pkgs < <(dpkg-query -W -f='${db:Status-Abbrev} ${binary:Package}\n' "php${v}-*" 2>/dev/null | awk '$1 ~ /^ii/ {print $2}')
    [ "${#pkgs[@]}" -gt 0 ] || return 0
    sys_note "· PHP $v ${cur:-?}: security fixes from $min, upgrading its packages"
    [ "${APT_UPDATED:-0}" = 1 ] || { studio_apt update; APT_UPDATED=1; }
    build=$(dpkg-query -W -f='${Version}' "php${v}-fpm" 2>/dev/null || true)
    studio_apt install --only-upgrade "${pkgs[@]}" || { sys_warn "PHP $v could not be upgraded"; return 0; }
    # Ubuntu's PHP keeps its number with the fixes in: restart only when the build changed
    if [ "$(dpkg-query -W -f='${Version}' "php${v}-fpm" 2>/dev/null || true)" != "$build" ]; then
        systemctl restart "php${v}-fpm" >/dev/null 2>&1 || sys_warn "php${v}-fpm did not restart"
        restart_outdated_services
    fi
    cur=$(php_patch_level "$v")
    if ver_ge "${cur:-0}" "$min"; then
        sys_note "  PHP $v is $cur"
    elif php_from_ubuntu "$v"; then
        sys_note "  PHP $v is Ubuntu's ($cur), on its newest package: Ubuntu's security fixes keep the version number"
    else
        sys_warn "PHP $v is still $cur: ${PHP_SOURCE:-its repository} does not serve $min yet; the nightly server update installs it once it does"
    fi
}

# The PHP versions really installed, comma separated, for Studio's project form.
installed_php_versions() {
    local d out=()
    for d in /etc/php/*/fpm; do [ -x "/usr/bin/php$(basename "$(dirname "$d")")" ] && out+=("$(basename "$(dirname "$d")")"); done
    (IFS=,; printf '%s' "${out[*]:-}")
}

ensure_composer() {
    if command -v composer >/dev/null 2>&1; then
        COMPOSER_ALLOW_SUPERUSER=1 composer self-update --2 --quiet >/dev/null 2>&1 || true
        return 0
    fi
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "php${STUDIO_PHP}" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
}

ensure_node() { # Node NODE_MAJOR or newer, from NodeSource
    local have=0
    command -v node >/dev/null 2>&1 && have=$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)
    [ "$have" -ge "$NODE_MAJOR" ] && return 0
    # NodeSource first; where its setup script does not know this Ubuntu yet, the archive's own
    # nodejs, which is fine when it is new enough
    if curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - >>"$STUDIO_APT_LOG" 2>&1; then
        APT_UPDATED=1
        studio_apt install nodejs
    else
        sys_warn "NodeSource has no packages for this Ubuntu yet: trying the nodejs Ubuntu ships"
        rm -f /etc/apt/sources.list.d/nodesource.list; APT_UPDATED=0
        apt_ensure nodejs npm
    fi
    have=$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)
    [ "$have" -ge "$NODE_MAJOR" ] || sys_warn "Node ${have} is installed, Studio wants ${NODE_MAJOR} or newer: the bridge and the asset build may fail"
}

ensure_claude_code() { # the newest Claude Code; workspaces have DISABLE_AUTOUPDATER, updates of Studio bring it
    npm install -g @anthropic-ai/claude-code@latest >/dev/null 2>&1 || sys_warn "Claude Code could not be installed with npm; workspaces fail until it is"
}

# ensure_cursor_cli — Cursor's official installer, which puts `agent` in $HOME/.local/bin.
# Installed under /usr/local/lib so every person's sign-in can run it (the helper's PATH),
# not under root's home, which they cannot read. The newest build, the way Claude Code is.
# CURSOR_CLI_HOME and CURSOR_CLI_BIN are the shared directory and the PATH symlink; tests
# point them at a temporary directory.
ensure_cursor_cli() {
    local home=${CURSOR_CLI_HOME:-/usr/local/lib/cursor-agent} link=${CURSOR_CLI_BIN:-/usr/local/bin/agent}
    mkdir -p "$home" "$(dirname "$link")"
    chmod 755 "$(dirname "$home")" "$home"
    # HOME has to be the shell that runs the installer. A prefix on curl alone leaves
    # bash as root, and the script writes ~/.local under /root: workspace users cannot
    # read it, and `command -v agent` never sees it.
    if ! curl -fsSL https://cursor.com/install | HOME="$home" bash >>"$STUDIO_APT_LOG" 2>&1; then
        sys_warn "Cursor CLI could not be installed; signing in to a Cursor subscription waits until it is"
        return 0
    fi
    chmod -R a+rX "$home"
    if [ -x "$home/.local/bin/agent" ]; then
        ln -sfn "$home/.local/bin/agent" "$link"
    else
        sys_warn "Cursor CLI installed but agent is not at $home/.local/bin/agent"
    fi
}

# ensure_agent_browser — the agents' browser: Playwright MCP at the version this release was tested
# with, and the Chromium its Playwright wants (with the libraries it needs), in one place every
# workspace reads. Without it the chats work as before, without screenshots.
ensure_agent_browser() {
    local ver=${PLAYWRIGHT_MCP_VERSION:-latest} root cli
    npm install -g "@playwright/mcp@${ver}" >/dev/null 2>&1 \
        || { sys_warn "Playwright MCP could not be installed with npm: the agents work without a browser"; return 0; }
    root=$(npm root -g)
    # its own Playwright: the Chromium build has to be the one that Playwright drives
    cli="$root/@playwright/mcp/node_modules/playwright/cli.js"; [ -f "$cli" ] || cli="$root/playwright/cli.js"
    mkdir -p "$STUDIO_BROWSERS"
    if ! PLAYWRIGHT_BROWSERS_PATH="$STUDIO_BROWSERS" node "$cli" install --with-deps chromium >>"$STUDIO_APT_LOG" 2>&1; then
        sys_warn "Chromium for the agents could not be installed (see $STUDIO_APT_LOG): the agents work without a browser"
        return 0
    fi
    chmod -R a+rX "$STUDIO_BROWSERS"
    ensure_browser_apparmor
}

# Ubuntu 23.10 and later refuse unprivileged user namespaces to programs AppArmor knows nothing of,
# and Chromium's sandbox needs one. A profile like Ubuntu's own for Chrome lets it keep the sandbox;
# where it cannot be loaded, the marker tells the bridges to start Chromium without it.
ensure_browser_apparmor() {
    rm -f "$STUDIO_BROWSERS/.no-sandbox"
    [ "$(sysctl -n kernel.apparmor_restrict_unprivileged_userns 2>/dev/null || echo 0)" = 1 ] || return 0
    if command -v apparmor_parser >/dev/null 2>&1 && [ -d /etc/apparmor.d ]; then
        cat > /etc/apparmor.d/studio-chromium <<PROFILE
abi <abi/4.0>,
include <tunables/global>

profile studio-chromium ${STUDIO_BROWSERS}/**/{chrome,headless_shell,chrome-headless-shell} flags=(unconfined) {
  userns,

  include if exists <local/studio-chromium>
}
PROFILE
        apparmor_parser -r /etc/apparmor.d/studio-chromium >/dev/null 2>&1 && return 0
    fi
    sys_warn "AppArmor would not take a profile for the agents' Chromium: it runs without its sandbox (still as the workspace's user)"
    touch "$STUDIO_BROWSERS/.no-sandbox"
}

# ensure_git_cli PROVIDER — the command-line tool the agents use for pull requests
ensure_git_cli() {
    case "$1" in
        github)
            command -v gh >/dev/null 2>&1 && return 0
            mkdir -p /etc/apt/keyrings && chmod 755 /etc/apt/keyrings
            curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o /etc/apt/keyrings/githubcli-archive-keyring.gpg
            echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" > /etc/apt/sources.list.d/github-cli.list
            APT_UPDATED=0
            apt_ensure gh ;;
        gitlab)
            # glab: the newest release's .deb, from GitLab itself
            local tag ver arch deb
            tag=$(curl -fsS --max-time 20 'https://gitlab.com/api/v4/projects/gitlab-org%2Fcli/releases?per_page=1' | jq -r '.[0].tag_name // empty') || true
            [ -n "$tag" ] || { sys_warn "could not find the latest glab release; the agents cannot open merge requests until glab is installed"; return 0; }
            ver=${tag#v}
            if command -v glab >/dev/null 2>&1 && glab version 2>/dev/null | grep -q "$ver"; then return 0; fi
            arch=$(dpkg --print-architecture)
            deb=$(mktemp --suffix=.deb)
            curl -fsSL --max-time 120 -o "$deb" "https://gitlab.com/gitlab-org/cli/-/releases/${tag}/downloads/glab_${ver}_linux_${arch}.deb" \
                && studio_apt install "$deb" >/dev/null || sys_warn "glab ${ver} could not be installed"
            rm -f "$deb" ;;
        azure)
            # Azure CLI from Microsoft's repository, plus the azure-devops extension for every user
            if ! command -v az >/dev/null 2>&1; then
                curl -fsSL https://aka.ms/InstallAzureCLIDeb | bash >/dev/null 2>&1 || { sys_warn "the Azure CLI could not be installed"; return 0; }
            fi
            az extension add --name azure-devops --system --yes --upgrade >/dev/null 2>&1 \
                || sys_warn "the azure-devops extension could not be installed or updated"
            az config set core.collect_telemetry=false >/dev/null 2>&1 || true ;;
        bitbucket)
            : # Larapilot talks to the Bitbucket REST API with curl; nothing to install
            ;;
    esac
}

ensure_databases() {
    apt_ensure mariadb-server postgresql redis-server
    systemctl enable --now mariadb postgresql redis-server >/dev/null 2>&1
}

ensure_layout() {
    getent group caddy >/dev/null || groupadd --system caddy
    id studio >/dev/null 2>&1 || useradd --system --create-home --home-dir /var/lib/studio --shell /usr/sbin/nologin studio
    usermod -aG caddy studio 2>/dev/null || true
    mkdir -p "$STUDIO_ETC/projects" "$STUDIO_ETC/workspaces" "$STUDIO_ETC/templates" /srv/studio/projects /srv/studio/workspaces \
        /etc/caddy/sites /usr/local/lib/studio /var/lib/studio-update /var/backups/studio \
        "$STUDIO_HOME/releases" "$STUDIO_HOME/shared/storage/app/public" "$STUDIO_HOME/shared/storage/framework/cache/data" \
        "$STUDIO_HOME/shared/storage/framework/sessions" "$STUDIO_HOME/shared/storage/framework/views" "$STUDIO_HOME/shared/storage/logs"
    chmod 750 "$STUDIO_ETC" "$STUDIO_ETC/projects" "$STUDIO_ETC/workspaces" /var/backups/studio
    chmod 755 /srv/studio /srv/studio/projects /srv/studio/workspaces /var/lib/studio-update /etc/caddy /etc/caddy/sites
    chown -R studio:studio "$STUDIO_HOME/shared"
    chmod 751 "$STUDIO_HOME" "$STUDIO_HOME/releases"
}

# The ports sshd listens on (sshd_config and its drop-ins), 22 when none is set:
# the firewall must not lock out whoever moved SSH off the standard port.
ssh_ports() {
    local ports
    # cat fails on a missing file or a drop-in glob that matches nothing (pipefail would then
    # stop the installer); what it could read is still the answer
    ports=$({ cat /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null || true; } | sed -n 's/^[[:space:]]*Port[[:space:]]\+\([0-9]\+\).*/\1/p' | sort -u)
    printf '%s\n' "${ports:-22}"
}

ensure_firewall() {
    local port
    for port in $(ssh_ports); do ufw allow "${port}/tcp" >/dev/null; done
    ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null
    ufw --force enable >/dev/null
}

# ensure_system RELEASE_DIR — everything the release needs; safe to run again and again
ensure_system() {
    local rel=$1 provider
    load_requirements "$rel"
    provider=$(env_get "$STUDIO_HOME/shared/.env" STUDIO_GIT_PROVIDER); provider=${provider:-${GIT_PROVIDER:-github}}
    sys_say "System (Ubuntu $(. /etc/os-release && echo "${VERSION_ID:-?}"), PHP ${PHP_VERSIONS// /, } where available, Node ${NODE_MAJOR}, ${provider})"
    sys_note "apt's own output goes to $STUDIO_APT_LOG; the slow steps are named as they start"
    sys_note "· base packages and Caddy"
    ensure_base_packages
    ensure_caddy
    sys_note "· PHP and its extensions"
    ensure_php
    sys_note "· Composer"
    ensure_composer
    sys_note "· Node ${NODE_MAJOR}"
    ensure_node
    sys_note "· Claude Code (npm; a minute or two)"
    ensure_claude_code
    sys_note "· Cursor CLI"
    ensure_cursor_cli
    sys_note "· the agents' browser: Playwright MCP and Chromium (a minute or two)"
    ensure_agent_browser
    sys_note "· the ${provider} command-line tool"
    ensure_git_cli "$provider"
    sys_note "· MariaDB, PostgreSQL, redis"
    ensure_databases
    ensure_layout
    ensure_firewall
    # Studio's project form offers the PHP versions that are really there
    [ -f "$STUDIO_HOME/shared/.env" ] && env_set "$STUDIO_HOME/shared/.env" STUDIO_PHP_VERSIONS "$(installed_php_versions)"
    return 0
}

# ---------------------------------------------------------- server upgrades

# package_versions -> "name version" for every installed package and for what Studio
# installs outside apt (Composer, Claude Code, the azure-devops extension)
package_versions() {
    local v
    dpkg-query -W -f='${db:Status-Abbrev} ${binary:Package} ${Version}\n' 2>/dev/null | awk '$1 ~ /^ii/ {print $2, $3}'
    v=$(COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-ansi 2>/dev/null | awk '{print $3}') || true
    [ -n "$v" ] && echo "composer-phar $v"
    v=$(claude --version 2>/dev/null | awk '{print $1}') || true
    [ -n "$v" ] && echo "claude-code $v"
    v=$(agent --version 2>/dev/null | awk '{print $NF}') || true
    [ -n "$v" ] && echo "cursor-agent $v"
    v=$(playwright-mcp --version 2>/dev/null | awk '{print $NF}') || true
    [ -n "$v" ] && echo "playwright-mcp $v"
    v=$(az extension show --name azure-devops --query version -o tsv 2>/dev/null) || true
    [ -n "$v" ] && echo "az-azure-devops $v"
    return 0
}

# changed_versions BEFORE AFTER -> "name old → new", "name new (new)", "name old (removed)"
changed_versions() {
    awk 'NR == FNR { old[$1] = $2; next }
        { seen[$1] = 1; if (!($1 in old)) print $1 " " $2 " (new)"; else if (old[$1] != $2) print $1 " " old[$1] " → " $2 }
        END { for (p in old) if (!(p in seen)) print p " " old[p] " (removed)" }' "$1" "$2" | sort
}

# The packages apt upgrades suspend needrestart (NEEDRESTART_SUSPEND): after the upgrade it
# runs once, restarting every service still on an old library, but never the updater that
# is running nor a workspace bridge (a conversation may be in progress: the next start of
# the workspace picks the new Node up).
restart_outdated_services() {
    command -v needrestart >/dev/null 2>&1 || return 0
    mkdir -p /etc/needrestart/conf.d
    cat > /etc/needrestart/conf.d/studio.conf <<'PERL'
# Studio: needrestart never restarts the updater while it runs, nor a workspace bridge
$nrconf{override_rc}{qr(^studio-(system-)?update)} = 0;
$nrconf{override_rc}{qr(^studio-bridge-)} = 0;
PERL
    NEEDRESTART_MODE=a needrestart -r a -q >>"$STUDIO_APT_LOG" 2>&1 || sys_warn "needrestart could not restart every outdated service"
}

# upgrade_system RELEASE_DIR CHANGES_FILE — every package on the server to its newest
# version: Ubuntu and the repositories Studio added (packages.sury.org or ppa:ondrej/php, Caddy, NodeSource,
# the provider's CLI), then Composer, Claude Code, glab and the azure-devops extension
# through the release's system step. CHANGES_FILE gets one line per package that changed.
# It stays on what is installed: a new PHP version comes with requirements.env; a new
# Ubuntu release, Node major or database major is a decision for a person.
upgrade_system() {
    local rel=$1 changes=$2 before after
    before=$(mktemp); after=$(mktemp)
    package_versions | sort > "$before"
    sys_say "Server update: every package to its newest version"
    sys_note "· apt: Ubuntu, PHP, Caddy, MariaDB, PostgreSQL, redis, Node, the git provider's CLI"
    studio_apt update
    APT_UPDATED=1
    studio_apt full-upgrade
    studio_apt autoremove
    studio_apt autoclean
    sys_note "· services still on old libraries"
    restart_outdated_services
    ensure_system "$rel"
    package_versions | sort > "$after"
    changed_versions "$before" "$after" > "$changes"
    rm -f "$before" "$after"
    if [ -s "$changes" ]; then
        sys_note "$(wc -l < "$changes" | tr -d ' ') changed:"
        sed 's/^/      /' "$changes"
    else
        sys_note "everything was already on its newest version"
    fi
}

# ------------------------------------------------------------- certificates
# Caddy issues every certificate on demand (after asking Studio) and renews it by itself
# when a third of its lifetime is left. check_certificates is the safety net of the nightly
# server update: a certificate past that point gets a TLS handshake, which makes Caddy
# renew an on-demand certificate, and whatever is still late is reported on the System page.

CADDY_CERTIFICATES=${CADDY_CERTIFICATES:-/var/lib/caddy/.local/share/caddy/certificates}
TLS_ASK_URL=${TLS_ASK_URL:-http://127.0.0.1:8081/api/tls/ask}

# cert_epoch FILE startdate|enddate -> the date, in seconds since 1970 (GNU date; BSD for tests)
cert_epoch() {
    local d
    d=$(openssl x509 -in "$1" -noout "-$2" 2>/dev/null | cut -d= -f2) || return 1
    [ -n "$d" ] || return 1
    date -u -d "$d" +%s 2>/dev/null || date -u -j -f '%b %e %T %Y %Z' "$d" +%s 2>/dev/null
}

# cert_state START END NOW -> ok; due (a third of its lifetime left: Caddy renews it now);
# late (a fifth left and not renewed: something stops Caddy); expired. In fractions of the
# lifetime, so it holds for 90-day, 45-day and 6-day certificates alike.
cert_state() {
    local life=$(($2 - $1)) left=$(($2 - $3))
    if [ "$left" -le 0 ]; then echo expired
    elif [ $((left * 5)) -lt "$life" ]; then echo late
    elif [ $((left * 3)) -lt "$life" ]; then echo due
    else echo ok
    fi
}

# latest_certificates -> "host file" of the newest certificate of each host (Caddy keeps
# one per issuer: Let's Encrypt, and ZeroSSL when it fell back to it)
latest_certificates() {
    local f
    [ -d "$CADDY_CERTIFICATES" ] || return 0
    find "$CADDY_CERTIFICATES" -mindepth 3 -maxdepth 3 -name '*.crt' | while IFS= read -r f; do
        printf '%s %s %s\n' "$(basename "$f" .crt)" "$(cert_epoch "$f" enddate || echo 0)" "$f"
    done | sort -k1,1 -k2,2nr | awk '!seen[$1]++ {print $1, $3}'
}

# host_wanted HOST: Studio still serves it (the question Caddy asks before issuing); the
# certificate of a deleted project or workspace is left to expire
host_wanted() {
    [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "${TLS_ASK_URL}?domain=$1" || true)" = 200 ]
}

renew_by_handshake() { # renew_by_handshake HOST — a client hello to the local Caddy for that name
    curl -sk -o /dev/null --max-time 90 --resolve "$1:443:127.0.0.1" "https://$1/" >/dev/null 2>&1 || true
}

# check_certificates OUT — one line per certificate of a host Studio serves, "host days state":
# ok, renewed (tonight), due (Caddy is renewing it), late or expired (Caddy could not renew it)
check_certificates() {
    local report=$1 seen host file start end now old state nudged=0
    seen=$(mktemp)
    : > "$report"
    now=$(date +%s)
    while read -r host file; do
        host_wanted "$host" || continue
        start=$(cert_epoch "$file" startdate) || continue
        end=$(cert_epoch "$file" enddate) || continue
        echo "$host $end" >> "$seen"
        if [ "$(cert_state "$start" "$end" "$now")" != ok ]; then
            sys_note "certificate of $host: $(( (end - now + 43200) / 86400 )) days left, renewing"
            renew_by_handshake "$host"
            nudged=1
        fi
    done < <(latest_certificates)
    # a certificate that has not expired yet is renewed in the background
    [ "$nudged" = 0 ] || sleep "${CERT_RENEW_WAIT:-30}"
    now=$(date +%s)
    while read -r host file; do
        old=$(awk -v h="$host" '$1 == h {print $2}' "$seen")
        [ -n "$old" ] || continue
        start=$(cert_epoch "$file" startdate) || continue
        end=$(cert_epoch "$file" enddate) || continue
        state=$(cert_state "$start" "$end" "$now")
        [ "$end" -le "$old" ] || state=renewed
        printf '%s %s %s\n' "$host" "$(( (end - now + 43200) / 86400 ))" "$state" >> "$report"
    done < <(latest_certificates)
    rm -f "$seen"
}

# ------------------------------------------------------------ release files

render_tpl() { # render_tpl TEMPLATE OUT KEY=VALUE...
    local tpl=$1 dest=$2 content kv k v; shift 2
    content=$(cat "$tpl")
    for kv in "$@"; do k=${kv%%=*}; v=${kv#*=}; content=${content//__${k}__/$v}; done
    printf '%s\n' "$content" > "$dest"
}

# install_release_files RELEASE_DIR — what lives outside the release: run before
# the switch for the new release, and again for the old one on a rollback
install_release_files() {
    local rel=$1 domain acme php v unit
    load_requirements "$rel"
    domain=$(env_get "$STUDIO_ETC/studio.env" DOMAIN)
    acme=$(env_get "$STUDIO_ETC/studio.env" ACME_EMAIL)
    php=$STUDIO_PHP

    install_file "$rel/installer/studio-admin" /usr/local/sbin/studio-admin 0755
    install_file "$rel/installer/studio-update" /usr/local/sbin/studio-update 0755
    # root only, no sudo rule: the way back in when every administrator is locked out
    install_file "$rel/installer/studio-recover" /usr/local/sbin/studio-recover 0700
    # root only too: the domain, its move to another one, the certificates
    [ -f "$rel/installer/studio-domain" ] && install_file "$rel/installer/studio-domain" /usr/local/sbin/studio-domain 0700
    install_file "$rel/installer/templates/askpass" /usr/local/lib/studio/askpass 0755
    rm -rf "$STUDIO_ETC/templates.new"; mkdir -p "$STUDIO_ETC/templates.new"
    cp "$rel"/installer/templates/* "$STUDIO_ETC/templates.new/"
    rm -rf "$STUDIO_ETC/templates.old"; [ -d "$STUDIO_ETC/templates" ] && mv "$STUDIO_ETC/templates" "$STUDIO_ETC/templates.old"
    mv "$STUDIO_ETC/templates.new" "$STUDIO_ETC/templates"; rm -rf "$STUDIO_ETC/templates.old"

    cat > /etc/sudoers.d/studio.new <<'EOF'
studio ALL=(root) NOPASSWD: /usr/local/sbin/studio-admin *
EOF
    chmod 440 /etc/sudoers.d/studio.new
    # Ubuntu 26.04 runs sudo-rs; check the rule with visudo when there is one
    if ! command -v visudo >/dev/null 2>&1 || visudo -cf /etc/sudoers.d/studio.new >/dev/null 2>&1; then
        mv -f /etc/sudoers.d/studio.new /etc/sudoers.d/studio
    else
        rm -f /etc/sudoers.d/studio.new
        sys_warn "visudo refused the sudoers rule for studio-admin; the previous one stays"
    fi

    # Studio's own PHP-FPM pool, on the PHP the release asks for (and on no other)
    for v in /etc/php/*/fpm/pool.d/studio.conf; do [ -f "$v" ] && [ "$v" != "/etc/php/${php}/fpm/pool.d/studio.conf" ] && rm -f "$v"; done
    render_tpl "$rel/installer/templates/fpm-pool.conf.tpl" "/etc/php/${php}/fpm/pool.d/studio.conf" \
        NAME=studio USER=studio GROUP=studio MAX=12 LOG=/var/lib/studio/php.log
    # The drive takes files up to STUDIO_DRIVE_MAX_UPLOAD_MB (100 MB by default) through Livewire;
    # no X-Powered-By header saying which PHP this is.
    printf 'php_admin_value[upload_max_filesize] = 256M\nphp_admin_value[post_max_size] = 260M\nphp_admin_value[max_execution_time] = 600\nphp_admin_flag[expose_php] = off\n' \
        >> "/etc/php/${php}/fpm/pool.d/studio.conf"

    for unit in studio-queue studio-backups studio-databases studio-reverb studio-scheduler; do
        render_tpl "$rel/installer/templates/${unit}.service" "/etc/systemd/system/${unit}.service" STUDIO_DIR="$STUDIO_HOME/current" PHP="php${php}"
    done
    install_ssh_gateway "$rel"
    render_tpl "$rel/installer/templates/studio-ssh.service" /etc/systemd/system/studio-ssh.service STUDIO_DIR="$STUDIO_HOME/current" STUDIO_HOME="$STUDIO_HOME"
    for unit in studio-update.service studio-update-force.service studio-update-auto.service studio-update-auto.timer \
        studio-system-update.service studio-system-update-auto.service studio-system-update-auto.timer; do
        cp "$rel/installer/templates/$unit" "/etc/systemd/system/$unit"
    done
    systemctl daemon-reload
    # the nightly server update is on unless an administrator turned it off (SYSTEM_UPDATE=0)
    if [ "$(env_get "$STUDIO_ETC/studio.env" SYSTEM_UPDATE)" != 0 ]; then
        systemctl enable --now studio-system-update-auto.timer >/dev/null 2>&1 || sys_warn "the nightly server update timer could not be enabled"
    fi

    # Caddy's admin API on a socket in /run/caddy, which only root and caddy enter (the Caddyfile
    # says `admin unix//run/caddy/admin.sock`): systemd makes the directory at every start; made
    # here too, for the Caddy already running. caddy_apply restarts a Caddy that still has no socket.
    mkdir -p /etc/systemd/system/caddy.service.d
    cp "$rel/installer/templates/caddy.override.conf" /etc/systemd/system/caddy.service.d/studio.conf
    systemctl daemon-reload
    install -d -o caddy -g caddy -m 0700 /run/caddy 2>/dev/null || true

    render_tpl "$rel/installer/templates/Caddyfile.tpl" /etc/caddy/Caddyfile.new DOMAIN="$domain" ACME_EMAIL="$acme" STUDIO_DIR="$STUDIO_HOME/current"
    touch /etc/caddy/sites/.keep
    caddy_readable
    if caddy validate --config /etc/caddy/Caddyfile.new --adapter caddyfile >/dev/null 2>&1; then
        mv -f /etc/caddy/Caddyfile.new /etc/caddy/Caddyfile
    else
        rm -f /etc/caddy/Caddyfile.new
        sys_warn "the new Caddyfile did not validate; the current one stays"
    fi
    env_set "$STUDIO_ETC/studio.env" STUDIO_PHP "$php"

    install_guards "$rel"
}

# install_guards RELEASE_DIR — fail2ban on Studio's security log (and sshd through the journal),
# and logrotate for that log. The log exists from the start, owned by studio, or the jail would
# not start on a fresh server; truncation at rotation keeps every writer on the same file.
install_guards() {
    local rel=$1 log="$STUDIO_HOME/shared/storage/logs/security.log"
    [ -f "$log" ] || install -o studio -g studio -m 0640 /dev/null "$log"
    render_tpl "$rel/installer/templates/logrotate.conf" /etc/logrotate.d/studio STUDIO_HOME="$STUDIO_HOME"
    chmod 644 /etc/logrotate.d/studio
    if [ -d /etc/fail2ban ]; then
        cp "$rel/installer/templates/fail2ban-filter.conf" /etc/fail2ban/filter.d/studio.conf
        render_tpl "$rel/installer/templates/fail2ban-jail.conf" /etc/fail2ban/jail.d/studio.conf STUDIO_HOME="$STUDIO_HOME"
        systemctl enable fail2ban >/dev/null 2>&1 || true
        systemctl reload-or-restart fail2ban 2>/dev/null || sys_warn "fail2ban did not start: journalctl -u fail2ban"
    fi
}

# install_ssh_gateway RELEASE_DIR — the SSH gateway's own dependencies (ssh2, ws), kept in
# the release: unlike Studio's assets it runs from there. A release that has them already
# (a rollback) is left as it is.
install_ssh_gateway() {
    local dir=$1/ssh-gateway
    [ -f "$dir/package-lock.json" ] || return 0
    [ -d "$dir/node_modules" ] && return 0
    chown -R studio:studio "$dir"
    ( cd "$dir" && sudo -u studio env HOME=/var/lib/studio npm ci --omit=dev --no-audit --no-fund --silent ) \
        || { sys_warn "npm ci of the SSH gateway failed: the SSH terminal stays unavailable"; return 0; }
}

# Caddy runs and reloads as user caddy: a file it cannot read under /etc/caddy fails every
# reload ("Could not import …: permission denied") and its next start. studio-admin up to
# 1.0 wrote the site files root-only (umask 027).
caddy_readable() {
    chmod 755 /etc/caddy /etc/caddy/sites 2>/dev/null || true
    chmod 644 /etc/caddy/Caddyfile /etc/caddy/sites/*.caddy 2>/dev/null || true
}

# caddy_apply — the Caddyfile into the running Caddy: a reload, through its admin socket; a
# restart when there is no socket to reload through (a Caddy started before the socket was
# configured, or one that is down). The restart is the one moment every host is away.
caddy_apply() {
    if systemctl is-active --quiet caddy && [ -S /run/caddy/admin.sock ]; then
        systemctl reload caddy
    else
        systemctl restart caddy
    fi
}

# Reload what serves Studio after a switch: PHP-FPM forgets the old release's
# paths and opcache, the workers restart on the new code.
restart_studio() {
    local v
    for v in /etc/php/*/fpm; do systemctl reload "php$(basename "$(dirname "$v")")-fpm" 2>/dev/null || true; done
    systemctl enable studio-queue studio-backups studio-databases studio-reverb studio-scheduler studio-ssh >/dev/null 2>&1 || true
    # a backup under way is cut short by the restart; the next schedule runs it again
    systemctl restart studio-queue studio-backups studio-databases studio-reverb studio-scheduler
    # Open SSH sessions end with a restart; a gateway that does not start leaves Studio up.
    systemctl restart studio-ssh || sys_warn "the SSH gateway (studio-ssh) did not start"
    systemctl enable --now caddy >/dev/null 2>&1 || true
    caddy_readable
    # a Caddy that is down (it could not read its files), or without its admin socket yet, restarts
    caddy_apply
}

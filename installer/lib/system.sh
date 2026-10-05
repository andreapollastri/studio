# shellcheck shell=bash
#
# Studio — the system a release needs, as idempotent steps run as root.
#
# Sourced by installer/install.sh (first install) and by studio-update (every
# update, from the NEW release, before it goes live), so a release can bring a
# new PHP, a newer Node, another package or a changed service with it:
#
#   ensure_system RELEASE_DIR      packages, PHP, Composer, Node, Claude Code, the
#                                  provider's CLI, databases, users and directories
#   install_release_files RELEASE_DIR
#                                  the root helper, the updater, templates, sudoers,
#                                  Studio's services, its PHP-FPM pool and the Caddyfile
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

studio_apt() {
    if ! DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 \
        apt-get -y -qq -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef "$@" >>"$STUDIO_APT_LOG" 2>&1; then
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
    STUDIO_PHP=8.4; PHP_VERSIONS="8.3 8.4 8.5"; NODE_MAJOR=22
    # shellcheck disable=SC1091
    [ -f "$1/installer/requirements.env" ] && . "$1/installer/requirements.env"
    resolve_studio_php
    export STUDIO_PHP PHP_VERSIONS NODE_MAJOR
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
    apt_ensure curl wget git unzip zip jq acl ufw fail2ban cron openssl ca-certificates gnupg lsb-release \
        software-properties-common unattended-upgrades debian-keyring debian-archive-keyring apt-transport-https sudo dnsutils
}

ensure_caddy() {
    command -v caddy >/dev/null 2>&1 && return 0
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>>"$STUDIO_APT_LOG"
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
    APT_UPDATED=0
    apt_ensure caddy
}

# ppa:ondrej/php, when it publishes packages for this Ubuntu release
ondrej_available() {
    curl -fsI --max-time 15 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/$1/Release" >/dev/null 2>&1
}

# Every version in PHP_VERSIONS this Ubuntu can install, with the extensions Laravel apps
# expect. The versions come from ppa:ondrej/php where it serves this release; where it
# does not yet (Ubuntu 26.04 at first), from Ubuntu's own archive, which carries one PHP.
ensure_php() {
    local codename v e pkgs avail=()
    codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
    if ondrej_available "$codename"; then
        if ! grep -rqs 'ondrej/php' /etc/apt/sources.list.d/; then
            add-apt-repository -y -n ppa:ondrej/php >>"$STUDIO_APT_LOG" 2>&1
            APT_UPDATED=0
        fi
    else
        # a source left behind for a release the PPA does not serve breaks every apt update
        if compgen -G '/etc/apt/sources.list.d/*ondrej*' >/dev/null; then
            rm -f /etc/apt/sources.list.d/*ondrej*
            APT_UPDATED=0
        fi
        sys_note "ppa:ondrej/php has no packages for Ubuntu ${codename:-this release} yet: PHP comes from Ubuntu's archive"
    fi
    [ "${APT_UPDATED:-0}" = 1 ] || { studio_apt update; APT_UPDATED=1; }

    for v in $PHP_VERSIONS; do
        if apt-cache show "php${v}-fpm" >/dev/null 2>&1; then avail+=("$v"); else sys_note "PHP $v is not available on Ubuntu ${codename}: skipped"; fi
    done
    if [ "${#avail[@]}" -eq 0 ]; then
        # none of the versions the release names: the PHP this Ubuntu ships
        v=$(apt-cache depends php-fpm 2>/dev/null | grep -o 'php[0-9][0-9]*\.[0-9][0-9]*-fpm' | head -n1 | sed 's/^php//; s/-fpm$//')
        [ -n "$v" ] || { sys_warn "no PHP-FPM package is available on this Ubuntu"; return 1; }
        avail=("$v")
    fi

    for v in "${avail[@]}"; do
        pkgs=()
        for e in cli fpm mysql pgsql mbstring xml curl zip gd intl bcmath readline sqlite3; do pkgs+=("php${v}-${e}"); done
        # OPcache is part of PHP itself from 8.5 on; before that it is a package of its own
        ver_ge "$v" 8.5 || pkgs+=("php${v}-opcache")
        apt_ensure "${pkgs[@]}"
        # redis: php8.x-redis from the PPA, or Ubuntu's php-redis (which provides it there)
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
        systemctl enable --now "php${v}-fpm" >/dev/null 2>&1
    done

    resolve_studio_php
    export STUDIO_PHP
    update-alternatives --set php "/usr/bin/php${STUDIO_PHP}" >/dev/null 2>&1 || true
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
            az extension show --name azure-devops >/dev/null 2>&1 \
                || az extension add --name azure-devops --system --yes >/dev/null 2>&1 \
                || sys_warn "the azure-devops extension could not be installed"
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
    ports=$(cat /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null | sed -n 's/^[[:space:]]*Port[[:space:]]\+\([0-9]\+\).*/\1/p' | sort -u)
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

    for unit in studio-queue studio-reverb studio-scheduler; do
        render_tpl "$rel/installer/templates/${unit}.service" "/etc/systemd/system/${unit}.service" STUDIO_DIR="$STUDIO_HOME/current" PHP="php${php}"
    done
    for unit in studio-update.service studio-update-force.service studio-update-auto.service studio-update-auto.timer; do
        cp "$rel/installer/templates/$unit" "/etc/systemd/system/$unit"
    done
    systemctl daemon-reload

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
}

# Caddy runs and reloads as user caddy: a file it cannot read under /etc/caddy fails every
# reload ("Could not import …: permission denied") and its next start. studio-admin up to
# 1.0 wrote the site files root-only (umask 027).
caddy_readable() {
    chmod 755 /etc/caddy /etc/caddy/sites 2>/dev/null || true
    chmod 644 /etc/caddy/Caddyfile /etc/caddy/sites/*.caddy 2>/dev/null || true
}

# Reload what serves Studio after a switch: PHP-FPM forgets the old release's
# paths and opcache, the workers restart on the new code.
restart_studio() {
    local v
    for v in /etc/php/*/fpm; do systemctl reload "php$(basename "$(dirname "$v")")-fpm" 2>/dev/null || true; done
    systemctl enable studio-queue studio-reverb studio-scheduler >/dev/null 2>&1 || true
    systemctl restart studio-queue studio-reverb studio-scheduler
    systemctl enable --now caddy >/dev/null 2>&1 || true
    caddy_readable
    # a Caddy that is down (it could not read its files) does not reload: start it
    systemctl reload-or-restart caddy
}

#!/usr/bin/env bash
# 2.1.0: the hardening release.
#   - Studio's .env learns the session settings of this release where they are missing: encrypted
#     sessions, a session cookie only Studio's host can set (__Host-), the password asked again
#     after an hour on the sensitive pages. The cookie's new name signs everyone out once.
#   - Everything else needs no script: install_release_files puts Caddy's admin socket, fail2ban's
#     jail, logrotate and the PHP-FPM flags in place on every update, and restart_studio restarts
#     Caddy once for the socket.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after)
        env="${STUDIO_HOME:-/opt/studio}/shared/.env"
        # shellcheck disable=SC1091
        . "$RELEASE/installer/lib/system.sh"
        [ -n "$(env_get "$env" SESSION_ENCRYPT)" ] || env_set "$env" SESSION_ENCRYPT true
        [ -n "$(env_get "$env" SESSION_COOKIE)" ] || env_set "$env" SESSION_COOKIE __Host-studio_session
        [ -n "$(env_get "$env" AUTH_PASSWORD_TIMEOUT)" ] || env_set "$env" AUTH_PASSWORD_TIMEOUT 3600
        ;;
esac

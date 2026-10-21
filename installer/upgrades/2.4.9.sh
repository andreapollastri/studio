#!/usr/bin/env bash
# 2.4.9
#   - A project's own addresses (site, previews, sandboxes) are always under Studio's domain,
#     behind the login; a client's domain gives only the site a public address, online or
#     offline per project. Previews that lived on a client's domain move back, and those that
#     were public go back to APP_ENV=local: apply every project's settings again (queued).
set -euo pipefail

case "${1:-}" in
    before) ;;
    after)
        STUDIO_PHP=8.4
        # shellcheck disable=SC1091
        [ -f "$RELEASE/installer/requirements.env" ] && . "$RELEASE/installer/requirements.env"
        # as studio-update's php_of: the release's PHP, or the newest one here
        if [ ! -x "/usr/bin/php$STUDIO_PHP" ]; then
            STUDIO_PHP=$(find /usr/bin -maxdepth 1 -name 'php[0-9]*.[0-9]*' 2>/dev/null | sed 's#.*/php##' | grep -E '^[0-9]+\.[0-9]+$' | sort -V | tail -n1)
        fi
        sudo -u studio env -i HOME=/var/lib/studio PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
            "php$STUDIO_PHP" "$RELEASE/artisan" studio:configure-projects --no-interaction \
            || echo "2.4.9: the projects could not be queued to be set up again; save each project's settings" >&2
        ;;
esac

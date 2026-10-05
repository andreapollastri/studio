# Studio — written by the installer.
#
# One wildcard DNS record, one Caddy, certificates issued on demand: before
# issuing one, Caddy asks Studio whether the host is known (studio itself, a
# project site, a workspace preview). Project and workspace hosts are added
# as files in /etc/caddy/sites/ by studio-admin.
{
    email __ACME_EMAIL__
    on_demand_tls {
        ask http://127.0.0.1:8081/api/tls/ask
    }
}

# Plain HTTP on the loopback, for Caddy itself (the "ask" endpoint, forward_auth), the
# bridges' callbacks and the updater's health check. Every other process on this server
# (a workspace's agent, a project's PHP) can reach this port too, so nothing else of
# Studio is served here: the dashboard is only at https://studio.__DOMAIN__.
# Any Host header: forward_auth keeps the visitor's Host, and a block for host 127.0.0.1
# answers that with an empty 200, which lets everyone in. The listener stays :8081 as it
# always was (closed to the outside by the firewall): a reload keeps the same socket.
http://:8081 {
    root * __STUDIO_DIR__/public
    @internal path /api/tls/ask /api/site-auth /api/bridge/* /up
    handle @internal {
        php_fastcgi unix//run/php/studio.sock
    }
    handle {
        respond "Not found" 404
    }
}

https:// {
    tls {
        on_demand
    }
    encode zstd gzip

    @not_studio not host studio.__DOMAIN__

    @studio host studio.__DOMAIN__
    handle @studio {
        root * __STUDIO_DIR__/public
        @ws path /app/* /apps/*
        reverse_proxy @ws 127.0.0.1:8080
        php_fastcgi unix//run/php/studio.sock
        file_server
    }

    # Every project host (site, /larapilot, previews, unknown names) asks Studio first: a person
    # logged in to Studio with access to the project, from an allowed address, or no page at all.
    # Here and not in each host's file, so that no host is ever served without it.
    forward_auth @not_studio 127.0.0.1:8081 {
        uri /api/site-auth
    }

    # Project sites and previews show inside Studio's right pane. An app that refuses every
    # frame (X-Frame-Options: DENY, frame-ancestors 'none') becomes "refused to connect"
    # there: Studio, and the page itself, may frame it; every other site still may not.
    header @not_studio {
        -X-Frame-Options
        Content-Security-Policy "frame-ancestors[^;]*" "frame-ancestors 'self' https://studio.__DOMAIN__"
        +Content-Security-Policy "frame-ancestors 'self' https://studio.__DOMAIN__"
        defer
    }

    import /etc/caddy/sites/*.caddy

    handle {
        respond "Unknown host" 404
    }
}

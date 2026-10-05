@__MATCHER__ host __HOST__
handle @__MATCHER__ {
    root * __ROOT__/public
__WS__
    php_fastcgi unix//run/php/__POOL__.sock {
        env APP_ENV __APP_ENV__
    }
    file_server
    @static___MATCHER__ path *.css *.js *.mjs *.map *.woff2 *.woff *.ttf *.svg *.png *.jpg *.jpeg *.gif *.webp *.avif *.ico
    header @static___MATCHER__ Cache-Control "public, max-age=604800"
}

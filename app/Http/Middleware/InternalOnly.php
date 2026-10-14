<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The endpoints Caddy, the workspace bridges and the SSH gateway call on
 * 127.0.0.1:8081 (routes/api.php): from anywhere else they do not exist. Caddy
 * already answers 404 for them on https://studio.<domain>; this is the same
 * answer from the app itself, for any way around Caddy.
 */
class InternalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('studio.security.internal_loopback_only') && ! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            abort(404);
        }

        return $next($request);
    }
}

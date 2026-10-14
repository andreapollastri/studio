<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every page of Studio answers with. Nothing may frame Studio, while
 * Studio frames project sites and previews (any https host); scripts, styles and
 * fonts come from Studio itself (Livewire and Alpine need inline scripts and eval,
 * so the policy is not strict there); the sockets of Reverb and the SSH terminal
 * are on Studio's own host. Over plain http (a development setup) the previews
 * and sockets are plain too. A file served with a policy of its own (the drive,
 * a screenshot of a conversation) keeps it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // No Permissions-Policy: it would apply to the preview pane too, where a project's app
        // may legitimately ask for the camera, the location or a payment.

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::policy($request->isSecure()));
        }

        if ($request->isSecure() && filled($hsts = config('studio.security.hsts'))) {
            $headers->set('Strict-Transport-Security', (string) $hsts);
        }

        return $response;
    }

    /** The content security policy of a page, for a secure (https) or a plain connection. */
    public static function policy(bool $secure): string
    {
        $remote = $secure ? 'https:' : 'https: http:';
        $sockets = $secure ? 'wss:' : 'wss: ws:';
        // Vite's dev server (npm run dev) serves the assets from another port over http
        $assets = $secure ? "'self'" : "'self' http: ws:";

        return implode('; ', [
            "default-src 'self'",
            "script-src {$assets} 'unsafe-inline' 'unsafe-eval' blob:",
            "style-src {$assets} 'unsafe-inline'",
            "font-src {$assets} data:",
            "img-src 'self' data: blob: {$remote}",
            "media-src 'self' data: blob:",
            "connect-src 'self' {$sockets}".($secure ? '' : ' http:'),
            "worker-src 'self' blob:",
            "frame-src {$remote}",
            "child-src 'self' blob: {$remote}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}

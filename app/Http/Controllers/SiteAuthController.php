<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Server\DashboardVisibility;
use App\Server\SiteAccess;
use App\Server\SitePass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy's `forward_auth` target for every project host: 2xx lets the request
 * through to the app (a public host, or a member), anything else goes back to
 * the visitor as is.
 * It runs without a session; the visitor proves membership with the pass
 * cookie of that host (see SitePass), never with Studio's own cookie.
 */
class SiteAuthController extends Controller
{
    public function __invoke(Request $request, SiteAccess $access, SitePass $passes): Response|RedirectResponse
    {
        $host = strtolower((string) ($request->header('X-Forwarded-Host') ?: $request->getHost()));
        $target = $access->resolveHost($host);

        // A name nobody configured: Caddy hands this answer to the visitor as is, so it is Studio's welcome page.
        if ($target === null) {
            return response()->view('site.welcome', ['host' => $host, 'hidden' => app(DashboardVisibility::class)->hidden()], 404);
        }

        $uri = (string) $request->header('X-Forwarded-Uri', '/');
        // not parse_url: `//larapilot` would read as a host, with no path at all
        $path = explode('?', $uri, 2)[0];

        if ($path === SitePass::CALLBACK) {
            return $this->redeem($uri, $host, $passes, $target['project']);
        }

        $user = $passes->userFromPass($request->cookies->get(SitePass::COOKIE), $host);
        $decision = $access->decide($target['project'], $target['kind'], $target['workspace'], $this->clientIp($request), $user, $target['branch'] ?? null, $path);

        if ($decision === SiteAccess::ALLOW) {
            return response('', 204);
        }

        if ($decision === SiteAccess::LOGIN && strtoupper((string) $request->header('X-Forwarded-Method', 'GET')) === 'GET') {
            $login = rtrim((string) config('app.url'), '/').route('site.login', ['host' => $host, 'r' => $this->safePath($uri)], absolute: false);

            return redirect()->away($login);
        }

        if ($decision === SiteAccess::LOGIN) {
            return response(__('Log in to Studio to use this site.'), 401);
        }

        return response(__('This site is not open from your address or account.'), 403);
    }

    /** The one-time token from Studio becomes this host's pass cookie, then the visitor goes where they were headed. */
    private function redeem(string $uri, string $host, SitePass $passes, Project $project): Response|RedirectResponse
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        // A repeated parameter (token[]=…) arrives as an array: only a plain string counts.
        $token = is_string($query['token'] ?? null) ? $query['token'] : '';
        $return = is_string($query['r'] ?? null) ? $query['r'] : '/';
        $user = $passes->redeemToken($token, $host);

        if ($user === null || ! $user->canAccess($project)) {
            return response(__('This sign-in link is not valid any more. Open the site again.'), 403);
        }

        return redirect()->to($this->safePath($return))
            ->withCookie(cookie(SitePass::COOKIE, $passes->passFor($user, $host), $passes->passLifetimeMinutes(), '/', null, true, true, false, 'lax'));
    }

    /** Only a path on the same host: no scheme, no `//host`, no backslashes. */
    private function safePath(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';
    }

    /** The visitor's address: what Caddy forwarded when the request came over the loopback. */
    private function clientIp(Request $request): string
    {
        $forwarded = trim((string) $request->header('X-Forwarded-For', ''));

        if ($forwarded !== '' && in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return trim(explode(',', $forwarded)[0]);
        }

        return (string) $request->ip();
    }
}

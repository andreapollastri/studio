<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Server\DashboardVisibility;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * A hidden dashboard (see DashboardVisibility) answers whoever has neither a session nor the
 * door's cookie with the page of a name nobody configured. A password reset link from an
 * email opens it too, when its token is good: the person may read the email in a browser
 * that never went through the door, and the token proves the link was sent to them.
 */
class HideDashboard
{
    public function __construct(private readonly DashboardVisibility $visibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->visibility->hidden() || $request->routeIs('door') || $request->is('up')) {
            return $next($request);
        }

        $opened = $this->visibility->cookieOpens($request->cookie(DashboardVisibility::COOKIE));

        if ($opened || $request->user() !== null || $this->validResetLink($request)) {
            $response = $next($request);

            // Signed in, or holding a good reset link: the next visit, signed out, still finds the sign-in page.
            if (! $opened) {
                $response->headers->setCookie($this->doorCookie());
            }

            return $response;
        }

        return response()->view('site.welcome', ['host' => $request->getHost(), 'hidden' => true], 404);
    }

    private function validResetLink(Request $request): bool
    {
        $token = $request->route('token');

        if (! $request->isMethod('GET') || ! $request->routeIs('password.reset') || ! is_string($token)) {
            return false;
        }

        $user = User::query()->where('email', (string) $request->query('email'))->first();

        return $user !== null && Password::broker()->tokenExists($user, $token);
    }

    private function doorCookie(): Cookie
    {
        return cookie(DashboardVisibility::COOKIE, $this->visibility->cookieValue(), DashboardVisibility::COOKIE_MINUTES, '/', null, true, true, false, 'lax');
    }
}

<?php

namespace App\Http\Controllers;

use App\Server\DashboardVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The door of a hidden dashboard: the right key leaves the door's cookie in the browser and
 * opens the sign-in page; any other key gets the same page as any unknown address.
 */
class DoorController extends Controller
{
    public function __invoke(string $key, DashboardVisibility $visibility): Response|RedirectResponse
    {
        if (! $visibility->opens($key)) {
            return $visibility->hidden()
                ? response()->view('site.welcome', ['host' => request()->getHost(), 'hidden' => true], 404)
                : redirect()->route('login');
        }

        return redirect()->route(auth()->check() ? 'home' : 'login')
            ->withCookie(cookie(DashboardVisibility::COOKIE, $visibility->cookieValue(), DashboardVisibility::COOKIE_MINUTES, '/', null, true, true, false, 'lax'));
    }
}

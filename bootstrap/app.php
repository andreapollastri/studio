<?php

use App\Http\Middleware\AuthenticateBridge;
use App\Http\Middleware\HideDashboard;
use App\Http\Middleware\InternalOnly;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'bridge' => AuthenticateBridge::class,
            // the endpoints meant for Caddy, the bridges and the SSH gateway: 127.0.0.1 only
            'internal' => InternalOnly::class,
        ]);

        // A hidden dashboard shows nothing to who has neither a session nor the door's cookie.
        // Before `auth`, or a guest would get its redirect to the sign-in page instead.
        $middleware->appendToGroup('web', HideDashboard::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: HideDashboard::class);

        // Every page: the security headers; a session that ends when the password changes from
        // another one (AuthenticateSession keeps the password's hash in the session); two-factor
        // set up before anything else when the installation requires it (after `auth`).
        $middleware->web(append: [AuthenticateSession::class, SecurityHeaders::class, RequireTwoFactor::class]);
        $middleware->appendToPriorityList(append: RequireTwoFactor::class, after: AuthenticatesRequests::class);

        // While an update migrates the database, Caddy still asks before issuing
        // certificates and before serving protected project hosts, and the bridges
        // keep reporting: those few endpoints stay up in maintenance mode.
        // admin/system/status: the System page follows an update through the maintenance of its switch
        $middleware->preventRequestsDuringMaintenance(except: ['api/tls/ask', 'api/site-auth', 'api/bridge/*', 'admin/system/status']);

        // The bridge streams the agent's text in slices: a trailing space is a
        // word boundary, and an empty string is still a string.
        TrimStrings::skipWhen(fn (Request $request) => $request->is('api/bridge/*'));
        ConvertEmptyStringsToNull::skipWhen(fn (Request $request) => $request->is('api/bridge/*'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

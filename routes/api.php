<?php

use App\Http\Controllers\Api\BridgeEventController;
use App\Http\Controllers\Api\GitWebhookController;
use App\Http\Controllers\Api\SshTicketController;
use App\Http\Controllers\Api\TlsAskController;
use App\Http\Controllers\SiteAuthController;
use Illuminate\Support\Facades\Route;

// The push webhook of the git provider (GitHub, GitLab, Bitbucket or Azure DevOps): the one
// endpoint here the outside world calls, with the project's secret.
Route::post('git/webhook/{project:slug}', GitWebhookController::class)
    ->name('api.git.webhook');

// The rest is for the processes of this server (Caddy, the workspace bridges, the SSH gateway),
// on 127.0.0.1:8081: from any other address these endpoints do not exist (InternalOnly).
Route::middleware('internal')->group(function () {
    Route::post('bridge/events', BridgeEventController::class)
        ->middleware('bridge')
        ->name('api.bridge.events');

    Route::get('tls/ask', TlsAskController::class)
        ->name('api.tls.ask');

    // Caddy's forward_auth for protected project hosts: no session, the visitor's pass cookie is per host.
    Route::get('site-auth', SiteAuthController::class)
        ->name('api.site.auth');

    // The SSH gateway redeems the page's one-time ticket for the connection, key or password included.
    Route::post('ssh/redeem', SshTicketController::class)
        ->middleware('throttle:120,1')
        ->name('api.ssh.redeem');
});

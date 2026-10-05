<?php

use App\Http\Controllers\Api\BridgeEventController;
use App\Http\Controllers\Api\GitWebhookController;
use App\Http\Controllers\Api\TlsAskController;
use App\Http\Controllers\SiteAuthController;
use Illuminate\Support\Facades\Route;

Route::post('bridge/events', BridgeEventController::class)
    ->middleware('bridge')
    ->name('api.bridge.events');

// The push webhook of the git provider (GitHub, GitLab, Bitbucket or Azure DevOps).
Route::post('git/webhook/{project:slug}', GitWebhookController::class)
    ->name('api.git.webhook');

Route::get('tls/ask', TlsAskController::class)
    ->name('api.tls.ask');

// Caddy's forward_auth for protected project hosts: no session, the visitor's pass cookie is per host.
Route::get('site-auth', SiteAuthController::class)
    ->name('api.site.auth');

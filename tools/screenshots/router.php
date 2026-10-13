<?php

/*
 * The front controller for screenshots: `php -S 127.0.0.1:8767 -t public tools/screenshots/router.php`.
 * It serves Studio as public/index.php does, with the git hosts and the clouds answering from
 * fake-apis.php (Http::fake), so the Git page, a project's Repository and Infrastructure tabs
 * show believable data. Nothing in the app knows about it; requests to anything else (the
 * bridge, the fake staging site) go out as usual.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$public = dirname(__DIR__, 2).'/public';
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path !== '/' && is_file($public.$path)) {
    return false;
}

define('LARAVEL_START', microtime(true));

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require_once dirname(__DIR__, 2).'/bootstrap/app.php';
$app->booted(fn () => Http::fake(require __DIR__.'/fake-apis.php'));
$app->handleRequest(Request::capture());

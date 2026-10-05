<?php

namespace App\Http\Controllers;

use App\Server\SiteAccess;
use App\Server\SitePass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Studio side of opening a protected project host: the person is logged
 * in to Studio (the auth middleware sends them through the login page first),
 * is a member of the project, and goes back to the host with a one-time token.
 */
class SiteLoginController extends Controller
{
    public function __invoke(Request $request, SiteAccess $access, SitePass $passes): RedirectResponse
    {
        $host = strtolower($request->string('host')->toString());
        $target = $access->resolveHost($host);

        abort_if($target === null, 404);
        abort_unless($request->user()->canAccess($target['project']), 403, __('You are not a member of this project.'));

        $path = $request->string('r', '/')->toString();
        $path = str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';

        return redirect()->away('https://'.$host.SitePass::CALLBACK.'?'.http_build_query([
            'token' => $passes->mintToken($request->user(), $host),
            'r' => $path,
        ]));
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bridge calls Studio back with the token Studio handed it for that turn.
 * The matching workspace rides along on the request.
 */
class AuthenticateBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return response()->json(['error' => 'Missing bridge token.'], 401);
        }

        $workspace = Workspace::findByCallbackToken($token);

        if ($workspace === null) {
            return response()->json(['error' => 'Unknown bridge token.'], 401);
        }

        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}

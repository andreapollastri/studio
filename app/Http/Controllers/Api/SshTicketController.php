<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Ssh\Gateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SSH gateway trades the page's one-time ticket for the connection it
 * stands for. Only the gateway knows the token (derived from APP_KEY).
 */
class SshTicketController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || ! hash_equals(Gateway::token(), $token)) {
            return response()->json(['error' => 'Invalid gateway token.'], 401);
        }

        $target = Gateway::redeem((string) $request->input('ticket', ''));

        return $target === null
            ? response()->json(['error' => 'Unknown or spent ticket.'], 404)
            : response()->json($target);
    }
}

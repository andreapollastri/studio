<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Server\Updates;
use Illuminate\Http\JsonResponse;

/**
 * The updater's state as JSON, for Administration → System to follow an
 * update live. It answers during the maintenance of the switch too, so the
 * page can wait for the new release and reload itself onto it.
 */
class SystemStatusController extends Controller
{
    public function __invoke(Updates $updates): JsonResponse
    {
        $state = $updates->state();
        $server = $updates->systemState();

        return response()->json([
            // the packages of the server, updated on their own (studio-update system)
            'server' => [
                'status' => $server['status'] ?? null,
                'message' => $server['message'] ?? null,
                'finished_at' => $server['finished_at'] ?? null,
                'log' => array_slice(array_values((array) ($server['log'] ?? [])), -40),
            ],
            'status' => $state['status'] ?? null,
            'message' => $state['message'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'current' => $state['current'] ?? null,
            'log' => array_slice(array_values((array) ($state['log'] ?? [])), -40),
        ])->header('Cache-Control', 'no-store');
    }
}

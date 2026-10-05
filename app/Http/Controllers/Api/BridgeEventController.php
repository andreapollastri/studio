<?php

namespace App\Http\Controllers\Api;

use App\Bridge\IngestBridgeEvents;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BridgeEventController extends Controller
{
    public function __invoke(Request $request, IngestBridgeEvents $ingest): JsonResponse
    {
        $validated = $request->validate([
            'conversation' => ['required'],
            'events' => ['required', 'array', 'max:500'],
            'events.*.type' => ['required', 'string', 'max:64'],
        ]);

        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');

        $conversation = Conversation::query()
            ->whereKey((int) $validated['conversation'])
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($conversation === null) {
            return response()->json(['error' => 'Unknown conversation for this workspace.'], 404);
        }

        $events = array_values((array) $request->input('events', []));

        $applied = $ingest->handle($workspace, $conversation, $events);

        return response()->json(['ok' => true, 'applied' => $applied]);
    }
}

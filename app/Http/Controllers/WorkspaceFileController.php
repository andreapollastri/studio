<?php

namespace App\Http\Controllers;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Livewire\Studio\Files;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file of the workspace (or of a sandbox's worktree) downloaded from the
 * Files tab, streamed from the bridge as it comes, for whoever has that file
 * manager. A folder goes as a zip: compress it first.
 */
class WorkspaceFileController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace): StreamedResponse
    {
        abort_unless(Files::allows($request->user(), $workspace), 404);

        $path = (string) $request->query('path', '');
        $sandbox = $request->filled('sandbox') ? (string) $request->query('sandbox') : null;

        try {
            $file = (new BridgeClient($workspace, $sandbox))->downloadFile($path);
        } catch (BridgeException $e) {
            abort(in_array($e->getCode(), [400, 403, 404], true) ? 404 : 502, $e->getMessage());
        }

        return response()->streamDownload(function () use ($file) {
            while (! $file['stream']->eof()) {
                echo $file['stream']->read(64 * 1024);
                flush();
            }
        }, basename($path), array_filter([
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => $file['size'],
            'X-Content-Type-Options' => 'nosniff',
        ], fn ($value) => $value !== null));
    }
}

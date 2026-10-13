<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Project;
use App\Support\ConversationImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A screenshot of a conversation, for whoever may read that conversation. */
class ConversationImageController extends Controller
{
    public function __invoke(Request $request, Project $project, Conversation $conversation, string $file): StreamedResponse
    {
        abort_unless($request->user()->can('view', $conversation) && $conversation->workspace->project_id === $project->id, 404);

        $path = ConversationImages::path($conversation, $file);
        abort_if($path === null, 404);

        $type = array_search(pathinfo($file, PATHINFO_EXTENSION), ConversationImages::TYPES, true) ?: 'application/octet-stream';

        return Storage::disk('local')->response($path, $file, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // a screenshot never changes: its name is new for every picture
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ], 'inline');
    }
}

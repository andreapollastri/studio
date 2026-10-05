<?php

namespace App\Http\Controllers\Api;

use App\Git\Providers\GitProvider;
use App\Http\Controllers\Controller;
use App\Jobs\DeployProjectSite;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A push to the deploy branch, reported by the git provider, deploys the project site. */
class GitWebhookController extends Controller
{
    public function __invoke(Request $request, Project $project): JsonResponse
    {
        $git = GitProvider::current();

        if (! $project->webhook_secret || ! $git->verifyWebhook($request, (string) $project->webhook_secret)) {
            return response()->json(['error' => 'Bad signature.'], 403);
        }

        $event = $git->webhookEvent($request);

        if ($event->type === 'ping') {
            return response()->json(['ok' => true, 'pong' => true]);
        }

        if (! $event->pushes($project->default_branch)) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        DeployProjectSite::dispatch($project);

        return response()->json(['ok' => true, 'deploying' => true], 202);
    }
}

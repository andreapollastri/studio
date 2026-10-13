<?php

namespace App\Workspaces;

use App\Actions\Studio\StartConversation;
use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Events\ConversationUpdated;
use App\Jobs\ProvisionSandbox;
use App\Jobs\RemoveSandbox;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Server\ServerException;
use App\Server\StudioAdmin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Conversations in a sandbox of their own: a git worktree of the person's
 * workspace on its own branch (the bridge creates it), and on a server its own
 * .env, database copied from the workspace's, build and preview host (the
 * helper's sandbox-create). Many can run at once, each with its Claude Code,
 * without touching the workspace or each other; the work comes back by
 * merging the branch into the workspace, or pushing it.
 */
final class Sandboxes
{
    public function __construct(private readonly StudioAdmin $admin) {}

    /** Why this person cannot open one more sandbox here, or null when they can. */
    public function refusal(Workspace $workspace, User $user): ?string
    {
        $settings = $workspace->project->agentSettings();

        if (! $settings['sandboxes']) {
            return __('Sandboxes are off for this project.');
        }

        if (! $workspace->isRunning()) {
            return __('Start the workspace first: a sandbox starts from it.');
        }

        $alive = $workspace->conversations()->where('user_id', $user->id)->whereNotNull('sandbox')->get()
            ->filter(fn (Conversation $conversation) => $conversation->sandboxAlive())->count();

        if ($alive >= $settings['sandbox_limit']) {
            return __('You already have :count sandboxes in this project: merge or remove one first.', ['count' => $alive]);
        }

        return null;
    }

    /** A new conversation whose sandbox is being prepared; it opens for writing once ready. */
    public function open(Workspace $workspace, User $user, StartConversation $start): Conversation
    {
        $refusal = $this->refusal($workspace, $user);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $conversation = $start->handle($workspace, $user);
        $conversation->forceFill([
            'sandbox' => [
                'status' => Conversation::SANDBOX_CREATING,
                'name' => $conversation->sandboxName(),
                'branch' => 'sandbox/'.($user->handle ?: 'user').'-'.$conversation->id,
            ],
            'sandbox_host' => $workspace->isNative() ? $workspace->project->sandboxHostFor($user, $conversation->id) : null,
        ])->save();

        ProvisionSandbox::dispatch($conversation);

        return $conversation;
    }

    /** The worktree through the bridge, then on a server its database, build and preview through the helper. */
    public function provision(Conversation $conversation): void
    {
        $workspace = $conversation->workspace;
        $project = $workspace->project;

        try {
            $state = (new BridgeClient($workspace))->createSandbox($conversation->sandboxName(), (string) $conversation->sandbox['branch']);
            $url = null;

            if ($workspace->isNative()) {
                $result = $this->admin->run('sandbox-create', [(string) $workspace->user->handle, $project->slug, (string) $conversation->id], [
                    'branch' => $state['branch'],
                    'php_version' => $project->php_version,
                    'db_engine' => $project->db_engine,
                    'preview_host' => (string) $conversation->sandbox_host,
                    'timezone' => $project->timezone(),
                    'public' => $project->isPublic('preview') ? '1' : '0',
                ]);
                $url = (string) ($result['app_url'] ?? 'https://'.$conversation->sandbox_host);
            }

            $conversation->updateSandbox([
                'status' => Conversation::SANDBOX_READY,
                'path' => $state['path'],
                'branch' => $state['branch'],
                'base' => $state['workspace_branch'],
                'url' => $url,
                'error' => null,
            ]);
        } catch (BridgeException|ServerException $e) {
            $conversation->updateSandbox(['status' => Conversation::SANDBOX_FAILED, 'error' => Str::limit($e->getMessage(), 1000)]);
        }

        $this->broadcast($conversation);
    }

    /** Try again after a failure: what the last attempt left is reused or replaced. */
    public function retry(Conversation $conversation): void
    {
        if ($conversation->sandboxStatus() !== Conversation::SANDBOX_FAILED) {
            return;
        }

        $conversation->updateSandbox(['status' => Conversation::SANDBOX_CREATING, 'error' => null]);
        ProvisionSandbox::dispatch($conversation);
    }

    /**
     * What is in the sandbox, committed on its branch, then merged into the branch the workspace
     * is on: all or nothing, a conflict leaves both as they were and says so.
     *
     * @return array<string, mixed> the workspace's git state after the merge
     */
    public function merge(Conversation $conversation): array
    {
        $this->ensureReady($conversation);
        $bridge = new BridgeClient($conversation->workspace);

        $bridge->commitSandbox($conversation->sandboxName(), $this->commitMessage($conversation));
        $state = $bridge->gitMerge((string) $conversation->sandbox['branch']);

        $conversation->updateSandbox(['merged_at' => now()->toIso8601String()]);
        $this->broadcast($conversation);

        return $state;
    }

    /**
     * The sandbox's branch committed and pushed to the remote, for a pull request there.
     *
     * @return array<string, mixed> the sandbox's git state after the push
     */
    public function push(Conversation $conversation): array
    {
        $this->ensureReady($conversation);

        (new BridgeClient($conversation->workspace))->commitSandbox($conversation->sandboxName(), $this->commitMessage($conversation));
        $state = BridgeClient::for($conversation)->gitPush();

        $conversation->updateSandbox(['pushed_at' => now()->toIso8601String()]);
        $this->broadcast($conversation);

        return $state;
    }

    /**
     * The sandbox's working tree and its distance from the workspace's branch, for the chat header.
     *
     * @return array<string, mixed>|null
     */
    public function state(Conversation $conversation): ?array
    {
        if (! $conversation->sandboxReady() || ! $conversation->workspace->isRunning()) {
            return null;
        }

        try {
            return (new BridgeClient($conversation->workspace))->sandboxState($conversation->sandboxName());
        } catch (BridgeException) {
            return null;
        }
    }

    /** Remove the sandbox, keeping the conversation as history; its branch goes too when discarded. */
    public function discard(Conversation $conversation, bool $deleteBranch): void
    {
        if (! $conversation->sandboxAlive()) {
            return;
        }

        $conversation->updateSandbox(['status' => Conversation::SANDBOX_REMOVING]);
        $this->broadcast($conversation);

        RemoveSandbox::dispatch($conversation->workspace, $conversation->id, $deleteBranch);
    }

    /**
     * The job's work: the bridge stops the agent in there and removes the worktree, the helper
     * the preview host and the database (and the worktree, when the bridge was not there to).
     * Runs after the conversation row is gone as well (a deleted conversation).
     */
    public function remove(Workspace $workspace, int $id, bool $deleteBranch): void
    {
        $name = 'c'.$id;

        if ($workspace->isRunning()) {
            try {
                (new BridgeClient($workspace))->removeSandbox($name, $deleteBranch);
            } catch (BridgeException $e) {
                Log::warning("Sandbox {$name}: the bridge did not remove it", ['workspace' => $workspace->uuid, 'error' => $e->getMessage()]);
            }
        }

        if ($workspace->isNative() && $workspace->user->handle) {
            try {
                $this->admin->run('sandbox-delete', [$workspace->user->handle, $workspace->project->slug, (string) $id], ['delete_branch' => $deleteBranch ? '1' : '0']);
            } catch (ServerException $e) {
                Log::error("Sandbox {$name} could not be removed from the server: {$e->getMessage()}");
            }
        }

        $conversation = $workspace->conversations()->find($id);

        if ($conversation !== null) {
            $conversation->forceFill(['sandbox_host' => null])->save();
            $conversation->updateSandbox(['status' => Conversation::SANDBOX_REMOVED, 'url' => null, 'path' => null]);
            $this->broadcast($conversation);
        }
    }

    private function ensureReady(Conversation $conversation): void
    {
        if (! $conversation->sandboxReady()) {
            throw new InvalidArgumentException(__('The sandbox is not ready.'));
        }

        if ($conversation->isBusy()) {
            throw new InvalidArgumentException(__('Claude is still working in the sandbox: wait for it, or interrupt it.'));
        }
    }

    private function commitMessage(Conversation $conversation): string
    {
        return Str::limit($conversation->displayTitle(), 72, '…');
    }

    private function broadcast(Conversation $conversation): void
    {
        rescue(fn () => ConversationUpdated::dispatch($conversation->uuid, 'sandbox', ['status' => $conversation->sandboxStatus()]), report: false);
    }
}

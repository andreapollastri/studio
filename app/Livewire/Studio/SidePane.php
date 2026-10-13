<?php

namespace App\Livewire\Studio;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Livewire\Attributes\On;
use Livewire\Component;

class SidePane extends Component
{
    public Workspace $workspace;

    public string $tab = 'preview';

    /** @var array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int, after?: list<string>}|null */
    public ?array $git = null;

    public ?string $gitError = null;

    /** What the last git command did, shown until the next one. */
    public ?string $gitNotice = null;

    public string $commitMessage = '';

    public string $newBranch = '';

    /** Which view of the Changes tab is open: the working tree, the history graph, or the branches. */
    public string $changesView = 'tree';

    /** @var array{branch: ?string, head: ?string, commits: list<array<string, mixed>>}|null */
    public ?array $log = null;

    /** @var array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}|null */
    public ?array $branches = null;

    /** @var array<string, mixed>|null the commit opened from the history */
    public ?array $commit = null;

    public string $command = '';

    /** @var array<string, mixed>|null the run being watched */
    public ?array $run = null;

    /** @var list<array<string, mixed>> earlier runs of this pane, newest first */
    public array $runs = [];

    public ?string $terminalError = null;

    /** The Files tab mounts on first use and then stays alive, hidden, so open editors survive tab switches. */
    public bool $filesMounted = false;

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function setTab(string $tab): void
    {
        $tabs = ['preview', ...(auth()->user()->canChat() ? ['changes'] : []), ...($this->canUseTerminal() ? ['terminal', 'files'] : [])];
        $this->tab = in_array($tab, $tabs, true) ? $tab : 'preview';

        if ($this->tab === 'files') {
            $this->filesMounted = true;
        }

        if ($this->tab === 'changes') {
            $this->loadChangesView();
        }
    }

    /** The workspace started or stopped: the preview, the terminal and the files follow. */
    #[On('workspace-updated')]
    public function onWorkspaceUpdated(): void
    {
        $this->workspace->refresh();

        if ($this->workspace->isRunning() && $this->tab === 'changes') {
            $this->loadChangesView();
        }
    }

    /** After every stored chat update the diff may have moved: refresh it while that tab is open. */
    #[On('chat-updated')]
    public function onChatUpdated(): void
    {
        if ($this->tab === 'changes') {
            $this->loadChangesView();
        }
    }

    public function setChangesView(string $view): void
    {
        $this->changesView = in_array($view, ['tree', 'history', 'branches'], true) ? $view : 'tree';
        $this->commit = null;
        $this->gitNotice = null;
        $this->loadChangesView();
    }

    private function loadChangesView(): void
    {
        $this->withGit(fn (BridgeClient $bridge) => $this->readGit($bridge));

        if ($this->gitError !== null && ! $this->workspace->isRunning()) {
            $this->git = null;
        }
    }

    /** The working tree state, which every view's header uses, and what the open view lists. */
    private function readGit(BridgeClient $bridge): void
    {
        $this->git = $bridge->git();
        $this->rememberBranch($this->git['branch']);

        match ($this->changesView) {
            'history' => $this->log = $bridge->gitLog(80),
            'branches' => $this->branches = $bridge->gitBranches(),
            default => null,
        };
    }

    public function showCommit(string $sha): void
    {
        $this->withGit(function (BridgeClient $bridge) use ($sha): void {
            $this->commit = $bridge->gitCommit($sha);
        });
    }

    public function closeCommit(): void
    {
        $this->commit = null;
    }

    /** Switch the workspace to a branch; the preview follows, and the access rules of that branch apply. */
    public function checkout(string $branch): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitCheckout($branch), __('Switched to :branch.', ['branch' => $branch]));
    }

    public function fetch(): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitFetch(), __('Fetched from the remote.'));
    }

    public function pullRemote(): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitPull(), fn (array $state) => $this->withNextSteps(__('Pulled from the remote.'), $state));
    }

    public function pushRemote(): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitPush(), __('Pushed to the remote.'));
    }

    /** Stage every change and commit it; while a merge is open this concludes it. */
    public function commitAll(): void
    {
        $message = trim($this->commitMessage);

        if ($message === '') {
            $this->gitError = __('Write a commit message first.');

            return;
        }

        $this->changeGit(function (BridgeClient $bridge) use ($message): void {
            $bridge->gitCommitAll($message);
            $this->commitMessage = '';
        }, __('Changes committed.'));
    }

    /** Throw away the changes of one file as git status lists it, or of the whole working tree. */
    public function discard(?string $path = null): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitDiscard($path), $path === null ? __('All changes discarded.') : __('Changes to :path discarded.', ['path' => $path]));
    }

    public function stash(): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitStash(), __('Changes put aside in a stash.'));
    }

    public function stashPop(): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitStash(pop: true), __('Stashed changes brought back.'));
    }

    /** Merge a branch into the current one, all or nothing: on a conflict the bridge undoes it and names the files. */
    public function merge(string $branch): void
    {
        $current = $this->git['branch'] ?? 'HEAD';

        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitMerge($branch), fn (array $state) => $this->withNextSteps(__(':branch merged into :current.', ['branch' => $branch, 'current' => $current]), $state));
    }

    /**
     * What the commits just brought in ask of the app, as commands the terminal runs.
     *
     * @param  array{after?: list<string>}  $state
     */
    private function withNextSteps(string $notice, array $state): string
    {
        $steps = $state['after'] ?? [];

        return $steps === [] ? $notice : $notice.' '.__('Run in the Terminal: :steps.', ['steps' => implode(', ', $steps)]);
    }

    public function abortMerge(): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitMergeAbort(), __('Merge aborted.'));
    }

    /** Create a branch from the current commit, or from the commit open in the history, and switch to it. */
    public function createBranch(?string $from = null): void
    {
        $name = trim($this->newBranch);

        if ($name === '') {
            $this->gitError = __('Give the new branch a name.');

            return;
        }

        $this->changeGit(function (BridgeClient $bridge) use ($name, $from): void {
            $bridge->gitCreateBranch($name, $from);
            $this->newBranch = '';
            $this->commit = null;
        }, __('Created and switched to :branch.', ['branch' => $name]));
    }

    public function deleteBranch(string $branch): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitDeleteBranch($branch), __('Branch :branch deleted.', ['branch' => $branch]));
    }

    /** A new commit that undoes one from the history. */
    public function revert(string $sha): void
    {
        $this->changeGit(function (BridgeClient $bridge) use ($sha): void {
            $bridge->gitRevert($sha);
            $this->commit = null;
        }, __('Commit reverted.'));
    }

    /**
     * Run one git command that changes the repository, then read it again whatever happened:
     * a failed merge or pull leaves something to see.
     */
    private function changeGit(callable $call, string|Closure $notice): void
    {
        if (! $this->canUseTerminal()) {
            abort(403);
        }

        $this->gitNotice = null;

        $this->withGit(function (BridgeClient $bridge) use ($call, $notice): void {
            $result = null;

            try {
                $result = $call($bridge);
            } finally {
                try {
                    $this->readGit($bridge);
                } catch (BridgeException) {
                    // the error of the command itself is the one worth showing
                }
            }

            $this->gitNotice = $notice instanceof Closure ? $notice(is_array($result) ? $result : []) : $notice;
        });
    }

    /** Run one git call against the bridge of a running workspace, keeping the error where the tab shows it. */
    private function withGit(callable $call): void
    {
        $this->workspace->refresh();

        if (! $this->workspace->isRunning()) {
            $this->gitError = __('The workspace is not running.');

            return;
        }

        try {
            $call(new BridgeClient($this->workspace));
            $this->gitError = null;
        } catch (BridgeException $e) {
            $this->gitError = $this->gitRefusal($e) ?? $e->getMessage();
        }
    }

    /** Git refusing for a reason of the repository (a conflict, uncommitted changes) is said as it is, not as a bridge error. */
    private function gitRefusal(BridgeException $e): ?string
    {
        $previous = $e->getPrevious();

        if (! $previous instanceof RequestException || $previous->response->status() !== 409) {
            return null;
        }

        $message = trim((string) $previous->response->json('error'));

        return $message === '' ? null : ucfirst($message);
    }

    /** The branch a workspace is on decides which access rule its preview gets, so keep it current. */
    private function rememberBranch(?string $branch): void
    {
        if ($branch !== null && $branch !== '' && $branch !== $this->workspace->branch) {
            $this->workspace->forceFill(['branch' => $branch])->save();
        }
    }

    /** Run one allowed command in the workspace through the bridge; the pane polls until it ends. */
    public function runCommand(?string $command = null): void
    {
        if (! $this->canUseTerminal()) {
            abort(403);
        }

        $command = trim($command ?? $this->command);

        if ($command === '') {
            return;
        }

        $this->workspace->refresh();

        if (! $this->workspace->isRunning()) {
            $this->terminalError = __('The workspace is not running.');

            return;
        }

        try {
            if ($this->run && ($this->run['status'] ?? '') === 'running') {
                $this->runs = array_slice([$this->run, ...$this->runs], 0, 10);
            }

            $this->run = (new BridgeClient($this->workspace))->run($command);
            $this->terminalError = null;
            $this->command = '';
        } catch (BridgeException $e) {
            $this->terminalError = $e->getMessage();
        }
    }

    public function refreshRun(): void
    {
        if (! $this->run || ($this->run['status'] ?? '') !== 'running') {
            return;
        }

        try {
            $this->run = (new BridgeClient($this->workspace))->runStatus((string) $this->run['id']);
        } catch (BridgeException $e) {
            $this->terminalError = $e->getMessage();
            $this->run['status'] = 'failed';
        }
    }

    public function killRun(): void
    {
        if (! $this->run || ! $this->canUseTerminal()) {
            return;
        }

        try {
            $this->run = (new BridgeClient($this->workspace))->killRun((string) $this->run['id']);
        } catch (BridgeException $e) {
            $this->terminalError = $e->getMessage();
        }
    }

    public function canUseTerminal(): bool
    {
        $user = auth()->user();

        return $user->canChat() && ($this->workspace->user_id === $user->id || $user->isAdmin());
    }

    public function render(): View
    {
        return view('livewire.studio.side-pane', [
            'canUseTerminal' => $this->canUseTerminal(),
            'hasWorkspace' => auth()->user()->canChat(),
            'shortcuts' => (array) config('studio.terminal', []),
            // Without a running workspace the staging site is the next best thing to look at.
            'previewUrl' => $this->workspace->isRunning() ? null : ($this->workspace->project->isOnline() ? $this->workspace->project->siteUrl() : null),
        ]);
    }
}

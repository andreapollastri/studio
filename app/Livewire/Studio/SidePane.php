<?php

namespace App\Livewire\Studio;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class SidePane extends Component
{
    public Workspace $workspace;

    public string $tab = 'preview';

    /** @var array{branch: ?string, status: list<string>, stat: string, diff: string}|null */
    public ?array $git = null;

    public ?string $gitError = null;

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
        $this->loadChangesView();
    }

    private function loadChangesView(): void
    {
        match ($this->changesView) {
            'history' => $this->loadHistory(),
            'branches' => $this->loadBranches(),
            default => $this->loadChanges(),
        };
    }

    public function loadHistory(): void
    {
        $this->withGit(function (BridgeClient $bridge): void {
            $this->log = $bridge->gitLog(80);
            $this->rememberBranch($this->log['branch']);
        });
    }

    public function loadBranches(): void
    {
        $this->withGit(function (BridgeClient $bridge): void {
            $this->branches = $bridge->gitBranches();
            $this->rememberBranch($this->branches['current']);
        });
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
        if (! $this->canUseTerminal()) {
            abort(403);
        }

        $this->withGit(function (BridgeClient $bridge) use ($branch): void {
            $this->git = $bridge->gitCheckout($branch);
            $this->rememberBranch($this->git['branch']);
            $this->branches = $bridge->gitBranches();
        });
    }

    public function fetch(): void
    {
        if (! $this->canUseTerminal()) {
            abort(403);
        }

        $this->withGit(function (BridgeClient $bridge): void {
            $this->branches = $bridge->gitFetch();
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
            $this->gitError = $e->getMessage();
        }
    }

    /** The branch a workspace is on decides which access rule its preview gets, so keep it current. */
    private function rememberBranch(?string $branch): void
    {
        if ($branch !== null && $branch !== '' && $branch !== $this->workspace->branch) {
            $this->workspace->forceFill(['branch' => $branch])->save();
        }
    }

    public function loadChanges(): void
    {
        $this->workspace->refresh();

        if (! $this->workspace->isRunning()) {
            $this->git = null;
            $this->gitError = __('The workspace is not running.');

            return;
        }

        try {
            $this->git = (new BridgeClient($this->workspace))->git();
            $this->gitError = null;
            $this->rememberBranch($this->git['branch']);
        } catch (BridgeException $e) {
            $this->git = null;
            $this->gitError = $e->getMessage();
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

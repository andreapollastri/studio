<?php

namespace App\Livewire\Studio;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Models\SshConnection;
use App\Models\Workspace;
use App\Ssh\Gateway;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class SidePane extends Component
{
    public Workspace $workspace;

    public string $tab = 'preview';

    /** @var array<string, mixed>|null the working tree as the bridge reads it (BridgeClient's GitState) */
    public ?array $git = null;

    public ?string $gitError = null;

    /** What the last git command did, shown until the next one. */
    public ?string $gitNotice = null;

    public string $commitMessage = '';

    /** The next commit rewrites the last one instead of adding one. */
    public bool $amend = false;

    public string $newBranch = '';

    /** Which view of the Changes tab is open: the working tree, the history graph, the branches, the tags or the stashes. */
    public string $changesView = 'tree';

    /** @var array{branch: ?string, head: ?string, commits: list<array<string, mixed>>}|null */
    public ?array $log = null;

    /** How many commits the history shows; "Load more" raises it. */
    public int $logLimit = 80;

    public string $logSearch = '';

    /** @var array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}|null */
    public ?array $branches = null;

    /** @var array<string, mixed>|null the commit opened from the history */
    public ?array $commit = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $tags = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $stashes = null;

    /** @var array{index: int, patch: string}|null the stash whose patch is open */
    public ?array $stashPatch = null;

    /** @var array{path: string, staged: string, unstaged: string, untracked: bool}|null the file of the working tree whose diff is open */
    public ?array $fileDiff = null;

    /** The merge being asked: the branch, what it would bring, and how. */
    public ?string $mergeBranch = null;

    /** @var array<string, mixed>|null */
    public ?array $mergePreview = null;

    public string $mergeMode = 'merge';

    /** @var array<string, mixed>|null the local branch being deleted, as the branch list has it */
    public ?array $deleting = null;

    public bool $deleteForce = false;

    public bool $deleteRemote = false;

    public ?string $renameFrom = null;

    public string $renameTo = '';

    /** @var array<string, mixed>|null the commit the current branch would be reset to */
    public ?array $resetTo = null;

    public string $resetMode = 'mixed';

    public string $tagName = '';

    public string $tagMessage = '';

    /** The commit the new tag goes on; null for the current one. */
    public ?string $tagAt = null;

    public ?string $deletingTag = null;

    public bool $deleteTagRemote = false;

    public string $stashMessage = '';

    public bool $stashUntracked = true;

    /** The Files tab mounts on first use and then stays alive, hidden, so open editors survive tab switches. */
    public bool $filesMounted = false;

    /**
     * The terminal is a shell in the workspace (local), on the project's own site (develop: the deploy
     * branch's, not the person's preview), or opens the project's SSH hosts (remote).
     */
    public string $terminalMode = 'local';

    /** The site's shell mounts on first use and stays, hidden, so its session survives tab switches. */
    public bool $developMounted = false;

    /** The workspace's shell mounts on first use and stays, hidden, so its session survives tab switches. */
    public bool $shellMounted = false;

    /** The remote terminals mount on first use and stay, hidden, so their sessions survive tab switches. */
    public bool $remoteMounted = false;

    /** The open conversation's sandbox, when it has one ready: git, terminal and files work in its worktree. */
    #[Locked]
    public ?string $sandbox = null;

    /** That sandbox's preview, which the Preview tab shows instead of the workspace's. */
    #[Locked]
    public ?string $sandboxUrl = null;

    public function mount(Workspace $workspace, ?string $sandbox = null, ?string $sandboxUrl = null): void
    {
        $this->workspace = $workspace;
        $this->sandbox = $sandbox;
        $this->sandboxUrl = $sandboxUrl;
    }

    private function bridge(): BridgeClient
    {
        return new BridgeClient($this->workspace, $this->sandbox);
    }

    public function setTab(string $tab): void
    {
        $tabs = ['preview', ...(auth()->user()->can('chat', $this->workspace->project) ? ['changes'] : []), ...($this->canUseTerminal() ? ['terminal', 'files'] : [])];
        $this->tab = in_array($tab, $tabs, true) ? $tab : 'preview';

        if ($this->tab === 'files') {
            $this->filesMounted = true;
        }

        if ($this->tab === 'terminal' && $this->terminalMode === 'local') {
            $this->shellMounted = true;
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
        $this->changesView = in_array($view, ['tree', 'history', 'branches', 'tags', 'stashes'], true) ? $view : 'tree';
        $this->commit = null;
        $this->fileDiff = null;
        $this->stashPatch = null;
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
            'history' => $this->log = $bridge->gitLog($this->logLimit, trim($this->logSearch)),
            'branches' => $this->branches = $bridge->gitBranches(),
            'tags' => $this->tags = $bridge->gitTags(),
            'stashes' => $this->stashes = $bridge->gitStashes(),
            default => $this->refreshFileDiff($bridge),
        };
    }

    /** The open file's diff follows the working tree; a file that has no changes left closes. */
    private function refreshFileDiff(BridgeClient $bridge): void
    {
        if ($this->fileDiff === null) {
            return;
        }

        $path = $this->fileDiff['path'];
        $files = is_array($this->git['files'] ?? null) ? $this->git['files'] : [];
        $listed = in_array($path, array_column($files, 'path'), true);
        $this->fileDiff = $listed ? $bridge->gitDiffFile($path) : null;
    }

    public function updatedLogSearch(): void
    {
        $this->logLimit = 80;
        $this->commit = null;
        $this->loadChangesView();
    }

    public function loadMoreLog(): void
    {
        $this->logLimit = min(400, $this->logLimit + 80);
        $this->loadChangesView();
    }

    /** Open one file of the working tree with its diff, staged and not. */
    public function openFile(string $path): void
    {
        $this->withGit(function (BridgeClient $bridge) use ($path): void {
            $this->fileDiff = $bridge->gitDiffFile($path);
        });
    }

    public function closeFile(): void
    {
        $this->fileDiff = null;
    }

    /** A commit named elsewhere (a tag) opened in the history. */
    public function openCommit(string $sha): void
    {
        $this->changesView = 'history';
        $this->loadChangesView();
        $this->showCommit($sha);
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

    /** After an amend or a reset: overwrite the remote branch, unless someone pushed to it meanwhile. */
    public function forcePush(): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitPush(force: true), __('Force pushed to the remote.'));
    }

    /** Stage one file as the working tree lists it, or every change. */
    public function stage(?string $path = null): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitStage($path === null ? null : [$path]), null);
    }

    public function unstage(?string $path = null): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitUnstage($path === null ? null : [$path]), null);
    }

    /**
     * The wand next to the message: Claude writes it from what would be committed (the staged
     * changes, or every change), in the field, to edit before committing.
     */
    public function suggestCommitMessage(string $what = 'all'): void
    {
        $this->authorizeGit();
        $this->gitError = null;

        try {
            $this->commitMessage = $this->bridge()->gitCommitMessage(auth()->user(), $what !== 'staged')['message'];
        } catch (BridgeException $e) {
            $this->gitError = $e->getMessage();
        }
    }

    /** Stage every change and commit it; while a merge is open this concludes it. */
    public function commitAll(): void
    {
        $this->commitChanges('all');
    }

    /**
     * Commit what is staged, or every change ("all"); with `push` the branch goes to the remote right after.
     * With "amend" ticked the last commit is rewritten, keeping its message when none is written.
     */
    public function commitChanges(string $what = 'staged', bool $push = false): void
    {
        $message = trim($this->commitMessage);
        $amend = $this->amend;

        if ($message === '' && ! $amend) {
            $this->gitError = __('Write a commit message first.');

            return;
        }

        $pushed = $amend && ($this->git['last']['pushed'] ?? false);

        $this->changeGit(function (BridgeClient $bridge) use ($message, $what, $amend, $push): void {
            $bridge->gitCommitAll($message, all: $what === 'all', amend: $amend);
            $this->commitMessage = '';
            $this->amend = false;
            $this->fileDiff = null;

            if ($push) {
                $bridge->gitPush();
            }
        }, match (true) {
            $push => __('Committed and pushed.'),
            $pushed => __('Last commit amended. It was already pushed: force push to replace it on the remote.'),
            $amend => __('Last commit amended.'),
            default => __('Changes committed.'),
        });
    }

    /** Ticking "amend" brings back the last commit's message to edit. */
    public function updatedAmend(bool $amend): void
    {
        if ($amend && trim($this->commitMessage) === '') {
            $this->commitMessage = (string) ($this->git['last']['message'] ?? '');
        }
    }

    /** Settle one conflicted file of an open merge: "ours", "theirs", or "resolved" as edited. */
    public function resolve(string $path, string $side): void
    {
        $label = match ($side) {
            'ours' => __(':path: kept this branch\'s version.', ['path' => $path]),
            'theirs' => __(':path: took the incoming version.', ['path' => $path]),
            default => __(':path marked as resolved.', ['path' => $path]),
        };

        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitResolve($path, $side), $label);
    }

    /** Throw away the changes of one file as git status lists it, or of the whole working tree. */
    public function discard(?string $path = null): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitDiscard($path), $path === null ? __('All changes discarded.') : __('Changes to :path discarded.', ['path' => $path]));
    }

    public function askStash(): void
    {
        $this->authorizeGit();

        $this->stashMessage = '';
        $this->stashUntracked = true;
        Flux::modal('git-stash')->show();
    }

    public function stash(): void
    {
        $message = trim($this->stashMessage);
        $untracked = $this->stashUntracked;
        Flux::modal('git-stash')->close();

        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitStashPush($message, $untracked), __('Changes put aside in a stash.'));
    }

    public function stashPop(): void
    {
        $this->stashApply(0);
    }

    /** Bring a stash back: `pop` drops it from the list, otherwise it stays to apply again. */
    public function stashApply(int $index, bool $pop = true): void
    {
        $this->changeGit(function (BridgeClient $bridge) use ($index, $pop): void {
            $bridge->gitStashApply($index, $pop);
            $this->stashPatch = null;
        }, $pop ? __('Stashed changes brought back.') : __('Stashed changes applied; the stash is kept.'));
    }

    public function stashDrop(int $index): void
    {
        $this->changeGit(function (BridgeClient $bridge) use ($index): void {
            $bridge->gitStashDrop($index);
            $this->stashPatch = null;
        }, __('Stash deleted.'));
    }

    public function showStash(int $index): void
    {
        if (($this->stashPatch['index'] ?? null) === $index) {
            $this->stashPatch = null;

            return;
        }

        $this->withGit(function (BridgeClient $bridge) use ($index): void {
            $this->stashPatch = ['index' => $index, 'patch' => $bridge->gitStashShow($index)];
        });
    }

    /** Open the merge dialog with what the branch would bring, conflicts foreseen included. */
    public function askMerge(string $branch): void
    {
        $this->authorizeGit();

        $this->mergeBranch = $branch;
        $this->mergeMode = 'merge';
        $this->mergePreview = null;

        $this->withGit(function (BridgeClient $bridge) use ($branch): void {
            $this->mergePreview = $bridge->gitCompare($branch);
        });

        Flux::modal('git-merge')->show();
    }

    /**
     * Merge a branch into the current one, all or nothing: on a conflict the bridge undoes it and names the files.
     * A squash stages the changes for one commit, whose message the commit form gets ready.
     */
    public function merge(?string $branch = null, ?string $mode = null): void
    {
        $branch ??= (string) $this->mergeBranch;
        $mode = in_array($mode ?? $this->mergeMode, ['merge', 'no-ff', 'squash'], true) ? ($mode ?? $this->mergeMode) : 'merge';
        $current = $this->git['branch'] ?? 'HEAD';
        $subjects = array_map(fn (array $c) => '- '.($c['subject'] ?? ''), $this->mergePreview['commits'] ?? []);
        Flux::modal('git-merge')->close();
        $this->mergeBranch = null;
        $this->mergePreview = null;

        $this->changeGit(function (BridgeClient $bridge) use ($branch, $mode, $subjects): array {
            $state = $bridge->gitMerge($branch, $mode);

            if ($mode === 'squash') {
                $this->commitMessage = trim(__('Squashed :branch', ['branch' => $branch])."\n\n".implode("\n", $subjects));
                $this->changesView = 'tree';
            }

            return $state;
        }, fn (array $state) => $mode === 'squash'
            ? __(':branch squashed: its changes are staged, commit them as one.', ['branch' => $branch])
            : $this->withNextSteps(__(':branch merged into :current.', ['branch' => $branch, 'current' => $current]), $state));
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

    /** Abort whatever git left open: a merge, a revert, a cherry-pick or a rebase. */
    public function abortMerge(): void
    {
        $operation = $this->git['operation'] ?? 'merge';

        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitMergeAbort(), __('The :operation was aborted.', ['operation' => $operation]));
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

    public function askRenameBranch(string $branch): void
    {
        $this->authorizeGit();

        $this->renameFrom = $branch;
        $this->renameTo = $branch;
        Flux::modal('git-rename-branch')->show();
    }

    public function renameBranch(): void
    {
        $from = (string) $this->renameFrom;
        $to = trim($this->renameTo);

        if ($to === '' || $to === $from) {
            Flux::modal('git-rename-branch')->close();

            return;
        }

        Flux::modal('git-rename-branch')->close();
        $this->renameFrom = null;

        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitRenameBranch($from, $to), __(':from renamed to :to.', ['from' => $from, 'to' => $to]));
    }

    /** Open the delete dialog: whether the branch is merged, and whether it has a branch on the remote. */
    public function askDeleteBranch(string $branch): void
    {
        $this->authorizeGit();

        $this->deleting = collect($this->branches['local'] ?? [])->firstWhere('name', $branch) ?? ['name' => $branch, 'merged' => true, 'upstream' => null];
        $this->deleteForce = false;
        $this->deleteRemote = false;
        Flux::modal('git-delete-branch')->show();
    }

    public function deleteBranch(?string $branch = null): void
    {
        $branch ??= (string) ($this->deleting['name'] ?? '');
        $force = $this->deleteForce;
        $remote = $this->deleteRemote && ! empty($this->deleting['upstream']) && ! ($this->deleting['gone'] ?? false);
        Flux::modal('git-delete-branch')->close();
        $this->deleting = null;
        $this->deleteForce = false;
        $this->deleteRemote = false;

        $this->changeGit(
            fn (BridgeClient $bridge) => $bridge->gitDeleteBranch($branch, $force, $remote),
            $remote ? __('Branch :branch deleted here and on the remote.', ['branch' => $branch]) : __('Branch :branch deleted.', ['branch' => $branch]),
        );
    }

    /** Delete a branch on the remote, as the remote list names it (origin/feature/x). */
    public function deleteRemoteBranch(string $branch): void
    {
        $this->changeGit(fn (BridgeClient $bridge) => $bridge->gitDeleteRemoteBranch($branch), __(':branch deleted on the remote.', ['branch' => $branch]));
    }

    /** A new commit that undoes one from the history. */
    public function revert(string $sha): void
    {
        $this->changeGit(function (BridgeClient $bridge) use ($sha): void {
            $bridge->gitRevert($sha);
            $this->commit = null;
        }, __('Commit reverted.'));
    }

    /** Copy a commit of another branch onto the current one. */
    public function cherryPick(string $sha): void
    {
        $this->changeGit(function (BridgeClient $bridge) use ($sha): void {
            $bridge->gitCherryPick($sha);
            $this->commit = null;
        }, __('Commit copied onto :branch.', ['branch' => $this->git['branch'] ?? 'HEAD']));
    }

    public function askReset(): void
    {
        $this->authorizeGit();

        if ($this->commit === null) {
            return;
        }

        $this->resetTo = $this->commit;
        $this->resetMode = 'mixed';
        Flux::modal('git-reset')->show();
    }

    /** Move the current branch back to the commit open in the history: soft, mixed or hard. */
    public function resetBranch(): void
    {
        $sha = (string) ($this->resetTo['sha'] ?? '');
        $short = (string) ($this->resetTo['short'] ?? $sha);
        $mode = in_array($this->resetMode, ['soft', 'mixed', 'hard'], true) ? $this->resetMode : 'mixed';
        Flux::modal('git-reset')->close();
        $this->resetTo = null;

        $this->changeGit(function (BridgeClient $bridge) use ($sha, $mode): array {
            $state = $bridge->gitReset($sha, $mode);
            $this->commit = null;

            return $state;
        }, fn (array $state) => __(':branch is back at :sha.', ['branch' => $state['branch'] ?? 'HEAD', 'sha' => $short])
            .($state['upstream'] !== null && ($state['behind'] ?? 0) > 0 ? ' '.__('The remote still has the later commits: force push to drop them there too.') : ''));
    }

    /** Open the tag dialog for the commit open in the history, or for the current one. */
    public function askTag(?string $at = null): void
    {
        $this->authorizeGit();

        $this->tagAt = $at;
        $this->tagName = '';
        $this->tagMessage = '';
        Flux::modal('git-tag')->show();
    }

    public function createTag(): void
    {
        $name = trim($this->tagName);

        if ($name === '') {
            $this->addError('tagName', __('Give the tag a name.'));

            return;
        }

        $at = $this->tagAt;
        $message = trim($this->tagMessage);
        Flux::modal('git-tag')->close();

        $this->changeGit(function (BridgeClient $bridge) use ($name, $at, $message): void {
            $this->tags = $bridge->gitCreateTag($name, $at, $message);
        }, __('Tag :tag created. Push it to share it.', ['tag' => $name]));
    }

    public function pushTags(?string $tag = null): void
    {
        $this->changeGit(function (BridgeClient $bridge) use ($tag): void {
            $this->tags = $bridge->gitPushTags($tag);
        }, $tag === null ? __('Tags pushed to the remote.') : __('Tag :tag pushed to the remote.', ['tag' => $tag]));
    }

    public function askDeleteTag(string $tag): void
    {
        $this->authorizeGit();

        $this->deletingTag = $tag;
        $this->deleteTagRemote = false;
        Flux::modal('git-delete-tag')->show();
    }

    public function deleteTag(): void
    {
        $tag = (string) $this->deletingTag;
        $remote = $this->deleteTagRemote;
        Flux::modal('git-delete-tag')->close();
        $this->deletingTag = null;

        $this->changeGit(function (BridgeClient $bridge) use ($tag, $remote): void {
            $this->tags = $bridge->gitDeleteTag($tag, $remote);
        }, $remote ? __('Tag :tag deleted here and on the remote.', ['tag' => $tag]) : __('Tag :tag deleted.', ['tag' => $tag]));
    }

    /**
     * Run one git command that changes the repository, then read it again whatever happened:
     * a failed merge or pull leaves something to see.
     */
    private function changeGit(callable $call, string|Closure|null $notice): void
    {
        $this->authorizeGit();

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

    /** Changing the repository is for whoever may use the workspace's terminal. */
    private function authorizeGit(): void
    {
        if (! $this->canUseTerminal()) {
            abort(403);
        }
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
            $call($this->bridge());
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
        // a sandbox's branch is its own, not the workspace's
        if ($this->sandbox === null && $branch !== null && $branch !== '' && $branch !== $this->workspace->branch) {
            $this->workspace->forceFill(['branch' => $branch])->save();
        }
    }

    /**
     * A one-time ticket for the gateway to open this workspace's shell (resources/js/workspace-shell.js
     * asks for it): the bridge runs it as the workspace user, in the workspace or the sandbox.
     *
     * @return array{ticket: string, url: string}|null
     */
    public function shellTicket(): ?array
    {
        abort_unless($this->canUseTerminal(), 403);

        $this->workspace->refresh();

        if (! $this->workspace->isRunning() || ! $this->workspace->hasBridge()) {
            return null;
        }

        return ['ticket' => Gateway::issueShell($this->workspace, auth()->user(), $this->sandbox), 'url' => Gateway::url()];
    }

    public function setTerminalMode(string $mode): void
    {
        $this->terminalMode = match (true) {
            $mode === 'remote' && $this->canUseRemote() => 'remote',
            $mode === 'develop' && $this->canUseDevelop() => 'develop',
            default => 'local',
        };

        match ($this->terminalMode) {
            'remote' => $this->remoteMounted = true,
            'develop' => $this->developMounted = true,
            default => $this->shellMounted = true,
        };
    }

    /** A shell on the project's own site: its administrators and developers, on a server Studio manages. */
    public function canUseDevelop(): bool
    {
        return $this->canUseTerminal() && Gateway::canDevelop(auth()->user(), $this->workspace->project);
    }

    /**
     * A one-time ticket for the gateway to open a shell on the project's site, as its user
     * (resources/js/workspace-shell.js asks for it). The password first, as for the remote hosts:
     * what changes there is live for whoever opens the site.
     *
     * @return array{ticket: string, url: string}|null
     */
    public function developTicket(): ?array
    {
        abort_unless($this->canUseDevelop(), 403);

        if (! $this->remoteConfirmed()) {
            return null;
        }

        return ['ticket' => Gateway::issueSiteShell($this->workspace->project, auth()->user()), 'url' => Gateway::url()];
    }

    /** SSH from the pane, as on the SSH page: who may use SSH at all. */
    public function canUseRemote(): bool
    {
        return $this->canUseTerminal() && auth()->user()->canUseSsh();
    }

    /** The SSH page asks for the password again; so does the pane, for as long as the page would. */
    public function remoteConfirmed(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);
    }

    /** The password page, then back to this workspace. */
    public function confirmPassword(): void
    {
        session()->put('url.intended', route('studio.show', $this->workspace->project));
        $this->redirect(route('password.confirm'));
    }

    /**
     * The project's SSH connections this person sees, with whether they can open each.
     *
     * @return Collection<int, array{connection: SshConnection, ready: bool}>
     */
    #[Computed]
    public function remoteConnections(): Collection
    {
        $user = auth()->user();

        return SshConnection::query()->visibleTo($user)->where('project_id', $this->workspace->project_id)->with('key')->orderBy('name')->get()
            ->map(fn (SshConnection $connection) => ['connection' => $connection, 'ready' => $connection->canConnect($user)]);
    }

    /**
     * A one-time ticket for the SSH gateway, as the SSH page gives (resources/js/ssh.js calls it).
     *
     * @return array{ticket: string, url: string, name: string, address: string, connection_id: string}|null
     */
    public function open(string $id): ?array
    {
        abort_unless($this->canUseRemote(), 403);

        if (! $this->remoteConfirmed()) {
            Flux::toast(variant: 'warning', text: __('Confirm your password first.'));

            return null;
        }

        $user = auth()->user();
        $connection = SshConnection::query()->visibleTo($user)->where('project_id', $this->workspace->project_id)->where('uuid', $id)->firstOrFail();

        if (! $connection->canConnect($user)) {
            Flux::toast(variant: 'warning', text: __('Set your own key or password for :name on the SSH page first.', ['name' => $connection->name]));

            return null;
        }

        return [
            'ticket' => Gateway::issue($connection, $user),
            'url' => Gateway::url(),
            'name' => $connection->name,
            'address' => $connection->address(),
            'connection_id' => $connection->uuid,
        ];
    }

    /** The person accepted the key a new host showed: later connections, of anybody, expect the same one. */
    public function trustHostKey(string $id, string $fingerprint): void
    {
        abort_unless($this->canUseRemote(), 403);

        if (! preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $fingerprint)) {
            return;
        }

        $connection = SshConnection::query()->visibleTo(auth()->user())->where('project_id', $this->workspace->project_id)->where('uuid', $id)->firstOrFail();

        if ($connection->host_key === null) {
            $connection->update(['host_key' => $fingerprint]);
        }
    }

    public function canUseTerminal(): bool
    {
        $user = auth()->user();

        return $user->can('chat', $this->workspace->project) && ($this->workspace->user_id === $user->id || $user->isAdmin());
    }

    public function render(): View
    {
        return view('livewire.studio.side-pane', [
            'canUseTerminal' => $this->canUseTerminal(),
            'canUseRemote' => $this->canUseRemote(),
            'canUseDevelop' => $this->canUseDevelop(),
            'hasWorkspace' => auth()->user()->can('chat', $this->workspace->project),
            'shortcuts' => (array) config('studio.terminal', []),
            // Without a running workspace the staging site is the next best thing to look at.
            'previewUrl' => $this->workspace->isRunning() ? null : ($this->workspace->project->isOnline() ? $this->workspace->project->siteUrl() : null),
            // a sandboxed conversation looks at its own preview
            'appUrl' => $this->sandboxUrl ?? $this->workspace->app_url,
        ]);
    }
}

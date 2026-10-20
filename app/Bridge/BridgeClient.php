<?php

namespace App\Bridge;

use App\Accounts\Accounts;
use App\Claude\AllowRules;
use App\Mcp\NativeServers;
use App\Models\Conversation;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\ProjectMcpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Server\SiteAccess;
use App\Server\SitePass;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;

/**
 * @phpstan-type GitLines array{added: ?int, removed: ?int}
 * @phpstan-type GitFile array{path: string, from: ?string, index: string, worktree: string, staged: bool, unstaged: bool, untracked: bool, conflicted: bool, lines: array{staged: ?GitLines, unstaged: ?GitLines}}
 * @phpstan-type GitState array{branch: ?string, detached: bool, status: list<string>, files: list<GitFile>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, operation: ?string, conflicts: list<string>, stashes: int, last: array{sha: string, short: string, subject: string, message: string, pushed: bool}|null, after: list<string>, squashed: ?string}
 *
 * Studio's side of the bridge protocol: plain HTTP calls into the Node bridge
 * that runs inside the workspace and drives Claude Code. Every turn tells the
 * bridge where to call back and with which token, so the bridge needs no
 * configuration about Studio beyond accepting this client's bearer token.
 *
 * With a sandbox name, the git and file calls work in that sandbox's worktree
 * instead of the workspace (the bridge reads it from X-Studio-Sandbox).
 */
final class BridgeClient
{
    public function __construct(private readonly Workspace $workspace, private readonly ?string $sandbox = null) {}

    /** The same bridge, its git and file calls pointed at a conversation's sandbox (or the workspace, without one). */
    public static function for(Conversation $conversation): self
    {
        return new self($conversation->workspace, $conversation->sandboxReady() ? $conversation->sandboxName() : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request(fn (PendingRequest $http) => $http->get('/health'));
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTurn(Conversation $conversation, string $text): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post("/conversations/{$conversation->id}/turns", ['text' => $text, ...$this->turn($conversation)]));
    }

    /**
     * Start the process of the conversation's next turn before it is written, with the settings it
     * will have: Enter then does not wait for the CLI to boot, resume and connect its MCP servers.
     *
     * @return array<string, mixed>
     */
    public function warm(Conversation $conversation): array
    {
        return $this->request(fn (PendingRequest $http) => $http->timeout(5)->post("/conversations/{$conversation->id}/warm", $this->turn($conversation)));
    }

    /**
     * Everything a turn carries but its text.
     *
     * @return array<string, mixed>
     */
    private function turn(Conversation $conversation): array
    {
        $agent = $conversation->agent === 'cursor' ? 'cursor' : 'claude';

        return [
            'agent' => $agent,
            'session_id' => $conversation->agent_session_id,
            'permission_mode' => $conversation->permission_mode,
            'model' => $agent === 'claude' ? $conversation->model : null,
            'effort' => $agent === 'claude' ? $conversation->effort : null,
            // Claude: the account the person works on this project with, even while it looks disconnected,
            // so the CLI never falls back to another login or key. Cursor: that person's subscription folder.
            ...($agent === 'cursor'
                ? ['cursor_dir' => Accounts::cursor($conversation->user)?->dir]
                : $this->claudeAccount($conversation)),
            'mcp_servers' => $this->mcpServers($conversation),
            'browser' => $this->browser($conversation),
            // a sandboxed conversation runs in its worktree; one whose sandbox is not ready never gets here
            'sandbox' => $conversation->isSandboxed() ? $conversation->sandboxName() : null,
            // what runs without asking: the role's everyday commands and the person's "Always allow"
            'allowed_tools' => AllowRules::forTurn($conversation->user, $conversation->workspace->project),
            'callback' => [
                'url' => $this->callbackUrl(),
                'token' => $this->workspace->callback_token,
            ],
        ];
    }

    /**
     * What a slash command can start in this conversation: the repository's skills and commands (the
     * sandbox's, when it has one), then the person's own in the Claude folder it signs in with.
     *
     * @return list<array{name: string, description: string, source: string, argument_hint?: string}>
     */
    public function skills(Conversation $conversation): array
    {
        $claudeDir = $this->claudeAccount($conversation)['claude_dir'] ?? null;
        $data = $this->request(fn (PendingRequest $http) => $http->get('/skills', array_filter(['claude_dir' => $claudeDir])));
        $skills = [];

        foreach ((array) ($data['skills'] ?? []) as $skill) {
            if (is_array($skill) && is_string($skill['name'] ?? null) && $skill['name'] !== '') {
                $skills[] = [
                    'name' => $skill['name'],
                    'description' => (string) ($skill['description'] ?? ''),
                    'source' => (string) ($skill['source'] ?? ''),
                    ...(isset($skill['argument_hint']) ? ['argument_hint' => (string) $skill['argument_hint']] : []),
                ];
            }
        }

        return $skills;
    }

    /**
     * How the turn signs in to Claude: auth, the account (a change restarts the CLI on the same
     * session), the API key or the folder of the subscription's sign-in in the person's home.
     *
     * @return array{auth: string|null, account?: string, api_key?: string|null, claude_dir?: string|null}
     */
    private function claudeAccount(Conversation $conversation): array
    {
        return $this->claudeAccountOf($conversation->user, $conversation->workspace->project);
    }

    /**
     * @return array{auth: string|null, account?: string, api_key?: string|null, claude_dir?: string|null}
     */
    private function claudeAccountOf(User $user, Project $project): array
    {
        $choice = Accounts::claude($user, $project);

        return $choice ? $choice->account->forTurn() : ['auth' => null];
    }

    /**
     * A commit message for the staged changes, or for every change ($all), written by Claude through the
     * bridge: one quick turn of its own on the person's Claude account, with a small model.
     *
     * @return array{message: string, model?: string}
     */
    public function gitCommitMessage(User $user, bool $all): array
    {
        $data = $this->request(fn (PendingRequest $http) => $http->timeout(150)->post('/git/commit-message', [
            'all' => $all,
            'model' => config('studio.commit_message_model'),
            ...$this->claudeAccountOf($user, $this->workspace->project),
        ]));

        return ['message' => (string) ($data['message'] ?? ''), 'model' => (string) ($data['model'] ?? '')];
    }

    /**
     * The preview the agent's browser opens, signed in as the person: the pass cookie a
     * project host's gatekeeper accepts, for that host alone (see SitePass). Null when the
     * project keeps the browser off or there is no preview to look at.
     *
     * @return array{url: string, cookies: list<array{name: string, value: string, expires: int}>}|null
     */
    public function browser(Conversation $conversation): ?array
    {
        $url = $conversation->previewUrl();

        if (! $conversation->workspace->project->agentSettings()['browser'] || blank($url)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $passes = app(SitePass::class);

        // Only a host behind Studio's gatekeeper wants the pass; a local preview opens as it is.
        $cookies = app(SiteAccess::class)->resolveHost($host) !== null ? [[
            'name' => SitePass::COOKIE,
            'value' => $passes->passFor($conversation->user, $host),
            'expires' => now()->addMinutes($passes->passLifetimeMinutes())->getTimestamp(),
        ]] : [];

        return ['url' => $url, 'cookies' => $cookies];
    }

    /**
     * A worktree for the conversation's sandbox, on its own branch from the workspace's current commit.
     *
     * @return array{name: string, path: string, branch: string, workspace_branch: string, ahead: int, behind: int, files: int, status: list<string>, stat: string, upstream: ?string}
     */
    public function createSandbox(string $name, string $branch): array
    {
        /** @var array{name: string, path: string, branch: string, workspace_branch: string, ahead: int, behind: int, files: int, status: list<string>, stat: string, upstream: ?string} */
        return $this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/sandboxes', ['name' => $name, 'branch' => $branch]));
    }

    /**
     * The sandbox's working tree and how far its branch is from the workspace's.
     *
     * @return array<string, mixed>
     */
    public function sandboxState(string $name): array
    {
        return $this->request(fn (PendingRequest $http) => $http->get('/sandboxes/'.rawurlencode($name)));
    }

    /**
     * Everything in the sandbox committed on its branch (nothing to commit is fine).
     *
     * @return array<string, mixed>
     */
    public function commitSandbox(string $name, string $message): array
    {
        return $this->request(fn (PendingRequest $http) => $http->timeout(60)->post('/sandboxes/'.rawurlencode($name).'/commit', ['message' => $message]));
    }

    /**
     * The worktree goes, and its branch with it when asked.
     *
     * @return array<string, mixed>
     */
    public function removeSandbox(string $name, bool $deleteBranch = false): array
    {
        return $this->request(fn (PendingRequest $http) => $http->timeout(60)->withQueryParameters(['branch' => $deleteBranch ? '1' : '0'])->delete('/sandboxes/'.rawurlencode($name)));
    }

    /**
     * @param  array<string, string>|null  $answers  AskUserQuestion: question text → the person's answer
     * @param  list<string>|null  $rules  "Always allow": rules the running session applies at once
     * @return array<string, mixed>
     */
    public function answerPermission(PermissionRequest $request, bool $allow, ?string $message = null, ?array $answers = null, ?array $rules = null): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post(
            "/conversations/{$request->conversation_id}/permissions/{$request->request_id}",
            ['behavior' => $allow ? 'allow' : 'deny', 'message' => $message, ...($answers ? ['answers' => $answers] : []), ...($rules ? ['rules' => $rules] : [])],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function interrupt(Conversation $conversation): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post("/conversations/{$conversation->id}/interrupt"));
    }

    /**
     * The conversation was deleted: the bridge stops its Claude Code and forgets it.
     *
     * @return array<string, mixed>
     */
    public function forget(Conversation $conversation): array
    {
        return $this->request(fn (PendingRequest $http) => $http->delete("/conversations/{$conversation->id}"));
    }

    /**
     * The working tree: branch, files staged and not, the diff against the last commit, the upstream,
     * what git is in the middle of and the last commit.
     *
     * @return GitState
     */
    public function git(): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->get('/git')));
    }

    /**
     * The newest commits of every branch, with parents, so the pane can draw the graph; `search`
     * keeps the commits whose message, author or id says it.
     *
     * @return array{branch: ?string, head: ?string, commits: list<array<string, mixed>>}
     */
    public function gitLog(int $limit = 80, string $search = ''): array
    {
        $data = $this->request(fn (PendingRequest $http) => $http->get('/git/log', array_filter(['limit' => $limit, 'search' => $search])));

        return [
            'branch' => isset($data['branch']) ? (string) $data['branch'] : null,
            'head' => isset($data['head']) ? (string) $data['head'] : null,
            'commits' => array_values(array_filter((array) ($data['commits'] ?? []), 'is_array')),
        ];
    }

    /**
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    public function gitBranches(): array
    {
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->get('/git/branches')));
    }

    /**
     * @return array<string, mixed> the commit with its body, stat and patch, and whether it is on the current branch
     */
    public function gitCommit(string $sha): array
    {
        return $this->request(fn (PendingRequest $http) => $http->get('/git/commit/'.rawurlencode($sha)));
    }

    /**
     * One file's diff: staged, not staged, or the whole of a new file.
     *
     * @return array{path: string, staged: string, unstaged: string, untracked: bool}
     */
    public function gitDiffFile(string $path): array
    {
        $data = $this->request(fn (PendingRequest $http) => $http->get('/git/diff', ['path' => $path]));

        return [
            'path' => (string) ($data['path'] ?? $path),
            'staged' => (string) ($data['staged'] ?? ''),
            'unstaged' => (string) ($data['unstaged'] ?? ''),
            'untracked' => (bool) ($data['untracked'] ?? false),
        ];
    }

    /**
     * What merging a branch would bring: its commits, the files it changes, a fast-forward or not,
     * and the files that would conflict (null when git cannot tell).
     *
     * @return array{branch: string, commits: list<array<string, mixed>>, ahead: int, behind: int, stat: string, files: list<string>, fastForward: bool, conflicts: list<string>|null}
     */
    public function gitCompare(string $branch): array
    {
        $data = $this->request(fn (PendingRequest $http) => $http->get('/git/compare', ['branch' => $branch]));

        return [
            'branch' => (string) ($data['branch'] ?? $branch),
            'commits' => array_values(array_filter((array) ($data['commits'] ?? []), 'is_array')),
            'ahead' => (int) ($data['ahead'] ?? 0),
            'behind' => (int) ($data['behind'] ?? 0),
            'stat' => (string) ($data['stat'] ?? ''),
            'files' => array_values(array_map('strval', (array) ($data['files'] ?? []))),
            'fastForward' => (bool) ($data['fastForward'] ?? false),
            'conflicts' => isset($data['conflicts']) && is_array($data['conflicts']) ? array_values(array_map('strval', $data['conflicts'])) : null,
        ];
    }

    /**
     * Switch the working tree to a branch; a remote branch is worked on through its local branch.
     *
     * @return GitState
     */
    public function gitCheckout(string $branch): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/checkout', ['branch' => $branch])));
    }

    /**
     * Fetch from the remote with the workspace's own credentials, tags included; the answer is the branches as they are now.
     *
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    public function gitFetch(): array
    {
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/git/fetch')));
    }

    /**
     * @param  list<string>|null  $paths  null for every change
     * @return GitState
     */
    public function gitStage(?array $paths = null): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/stage', ['paths' => $paths])));
    }

    /**
     * @param  list<string>|null  $paths  null for every staged file
     * @return GitState
     */
    public function gitUnstage(?array $paths = null): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/unstage', ['paths' => $paths])));
    }

    /**
     * Commit every change (`all`) or what is staged; `amend` rewrites the last commit. While a merge is open this concludes it.
     *
     * @return GitState
     */
    public function gitCommitAll(string $message, bool $all = true, bool $amend = false): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/commit', ['message' => $message, 'all' => $all, 'amend' => $amend])));
    }

    /**
     * Settle one conflicted file of an open merge: ours, theirs, or resolved as edited.
     *
     * @return GitState
     */
    public function gitResolve(string $path, string $side): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/resolve', ['path' => $path, 'side' => $side])));
    }

    /**
     * Throw away uncommitted changes: one path as git status lists it, or everything.
     *
     * @return GitState
     */
    public function gitDiscard(?string $path = null): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/discard', ['path' => $path])));
    }

    /**
     * Pull the current branch with a merge, all or nothing.
     *
     * @return GitState
     */
    public function gitPull(): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/git/pull')));
    }

    /**
     * Push the current branch, setting its upstream the first time; `force` overwrites the remote branch with a lease.
     *
     * @return GitState
     */
    public function gitPush(bool $force = false): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/git/push', ['force' => $force])));
    }

    /**
     * Merge a branch into the current one, all or nothing: "merge" (fast-forward when possible), "no-ff" or "squash".
     *
     * @return GitState
     */
    public function gitMerge(string $branch, string $mode = 'merge'): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/merge', ['branch' => $branch, 'mode' => $mode])));
    }

    /**
     * Abort whatever git left open: a merge, a revert, a cherry-pick or a rebase.
     *
     * @return GitState
     */
    public function gitMergeAbort(): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/merge/abort')));
    }

    /**
     * Create a branch from the current commit or the one given, and switch to it.
     *
     * @return GitState
     */
    public function gitCreateBranch(string $branch, ?string $from = null): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/branches', ['branch' => $branch, 'from' => $from])));
    }

    /**
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    public function gitRenameBranch(string $from, string $to): array
    {
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->post('/git/branches/rename', ['from' => $from, 'to' => $to])));
    }

    /**
     * Delete a local branch: git refuses one merged nowhere unless `force`; `remote` deletes its branch on the remote too.
     *
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    public function gitDeleteBranch(string $branch, bool $force = false, bool $remote = false): array
    {
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->withQueryParameters(array_filter(['branch' => $branch, 'force' => $force ? 1 : null, 'remote' => $remote ? 1 : null]))->delete('/git/branches')));
    }

    /**
     * Delete a branch on its remote, named as listed (origin/feature/x).
     *
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    public function gitDeleteRemoteBranch(string $branch): array
    {
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->withQueryParameters(['branch' => $branch])->delete('/git/remote-branches')));
    }

    /**
     * A new commit that undoes one from the history.
     *
     * @return GitState
     */
    public function gitRevert(string $sha): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/revert', ['sha' => $sha])));
    }

    /**
     * Copy a commit of another branch onto the current one, all or nothing.
     *
     * @return GitState
     */
    public function gitCherryPick(string $sha): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/cherry-pick', ['sha' => $sha])));
    }

    /**
     * Move the current branch to a commit: soft, mixed or hard.
     *
     * @return GitState
     */
    public function gitReset(string $sha, string $mode): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/reset', ['sha' => $sha, 'mode' => $mode])));
    }

    /**
     * @return list<array<string, mixed>> name, annotated, sha, date, message, pushed
     */
    public function gitTags(): array
    {
        return $this->tagsFrom($this->request(fn (PendingRequest $http) => $http->get('/git/tags')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function gitCreateTag(string $tag, ?string $at = null, string $message = ''): array
    {
        return $this->tagsFrom($this->request(fn (PendingRequest $http) => $http->post('/git/tags', ['tag' => $tag, 'at' => $at, 'message' => $message])));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function gitDeleteTag(string $tag, bool $remote = false): array
    {
        return $this->tagsFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->withQueryParameters(array_filter(['tag' => $tag, 'remote' => $remote ? 1 : null]))->delete('/git/tags')));
    }

    /**
     * Push one tag, or all of them.
     *
     * @return list<array<string, mixed>>
     */
    public function gitPushTags(?string $tag = null): array
    {
        return $this->tagsFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/git/tags/push', ['tag' => $tag])));
    }

    /**
     * @return list<array<string, mixed>> index, branch, message, date, files
     */
    public function gitStashes(): array
    {
        $data = $this->request(fn (PendingRequest $http) => $http->get('/git/stashes'));

        return array_values(array_filter((array) ($data['stashes'] ?? []), 'is_array'));
    }

    public function gitStashShow(int $index): string
    {
        return (string) ($this->request(fn (PendingRequest $http) => $http->get('/git/stash/show', ['index' => $index]))['patch'] ?? '');
    }

    /**
     * Put the changes aside, new files too unless `untracked` is false.
     *
     * @return GitState
     */
    public function gitStashPush(string $message = '', bool $untracked = true): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/stash', ['message' => $message, 'untracked' => $untracked])));
    }

    /**
     * Bring a stash back, dropping it (`pop`) or keeping it in the list.
     *
     * @return GitState
     */
    public function gitStashApply(int $index = 0, bool $pop = true): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/stash/apply', ['index' => $index, 'pop' => $pop])));
    }

    /**
     * @return GitState
     */
    public function gitStashDrop(int $index): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->withQueryParameters(['index' => $index])->delete('/git/stash')));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    private function branchesFrom(array $data): array
    {
        return [
            'current' => isset($data['current']) ? (string) $data['current'] : null,
            'local' => array_values(array_filter((array) ($data['local'] ?? []), 'is_array')),
            'remote' => array_values(array_filter((array) ($data['remote'] ?? []), 'is_array')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function tagsFrom(array $data): array
    {
        return array_values(array_filter((array) ($data['tags'] ?? []), 'is_array'));
    }

    /**
     * One `git status --porcelain` line ("XY path" or "XY old -> new") as the bridge describes a file.
     *
     * @return array<string, mixed>
     */
    private function fileFromStatus(string $line): array
    {
        $x = $line[0] ?? ' ';
        $y = $line[1] ?? ' ';
        $path = substr($line, 3);
        $from = null;

        if (in_array($x, ['R', 'C'], true) && str_contains($path, ' -> ')) {
            [$from, $path] = explode(' -> ', $path, 2);
        }

        $conflicted = in_array($x.$y, ['DD', 'AU', 'UD', 'UA', 'DU', 'AA', 'UU'], true);

        return [
            'path' => $path,
            'from' => $from,
            'index' => $x,
            'worktree' => $y,
            'staged' => ! $conflicted && $x !== ' ' && $x !== '?',
            'unstaged' => ! $conflicted && ($y !== ' ' || $x === '?'),
            'untracked' => $x === '?',
            'conflicted' => $conflicted,
        ];
    }

    /**
     * Lines added and removed in one file, null where git did not count them (a binary file).
     *
     * @return array{added: ?int, removed: ?int}|null
     */
    private function linesFrom(mixed $lines): ?array
    {
        if (! is_array($lines)) {
            return null;
        }

        return [
            'added' => isset($lines['added']) ? (int) $lines['added'] : null,
            'removed' => isset($lines['removed']) ? (int) $lines['removed'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GitState
     */
    private function stateFrom(array $data): array
    {
        $files = [];
        // an older bridge sends only the porcelain lines: the files are read from them
        $listed = isset($data['files']) ? (array) $data['files'] : array_map(fn ($line) => $this->fileFromStatus((string) $line), (array) ($data['status'] ?? []));

        foreach ($listed as $file) {
            if (! is_array($file) || ! isset($file['path'])) {
                continue;
            }

            $files[] = [
                'path' => (string) $file['path'],
                'from' => isset($file['from']) ? (string) $file['from'] : null,
                'index' => (string) ($file['index'] ?? ' '),
                'worktree' => (string) ($file['worktree'] ?? ' '),
                'staged' => (bool) ($file['staged'] ?? false),
                'unstaged' => (bool) ($file['unstaged'] ?? false),
                'untracked' => (bool) ($file['untracked'] ?? false),
                'conflicted' => (bool) ($file['conflicted'] ?? false),
                // an older bridge does not count the lines: the list then shows none
                'lines' => [
                    'staged' => $this->linesFrom($file['lines']['staged'] ?? null),
                    'unstaged' => $this->linesFrom($file['lines']['unstaged'] ?? null),
                ],
            ];
        }

        $last = is_array($data['last'] ?? null) ? [
            'sha' => (string) ($data['last']['sha'] ?? ''),
            'short' => (string) ($data['last']['short'] ?? ''),
            'subject' => (string) ($data['last']['subject'] ?? ''),
            'message' => (string) ($data['last']['message'] ?? ''),
            'pushed' => (bool) ($data['last']['pushed'] ?? false),
        ] : null;

        return [
            'branch' => isset($data['branch']) ? (string) $data['branch'] : null,
            'detached' => (bool) ($data['detached'] ?? false),
            'status' => array_values(array_map('strval', (array) ($data['status'] ?? []))),
            'files' => $files,
            'stat' => (string) ($data['stat'] ?? ''),
            'diff' => (string) ($data['diff'] ?? ''),
            'upstream' => isset($data['upstream']) ? (string) $data['upstream'] : null,
            'ahead' => (int) ($data['ahead'] ?? 0),
            'behind' => (int) ($data['behind'] ?? 0),
            'merging' => (bool) ($data['merging'] ?? false),
            'operation' => isset($data['operation']) ? (string) $data['operation'] : (($data['merging'] ?? false) ? 'merge' : null),
            'conflicts' => array_values(array_map('strval', (array) ($data['conflicts'] ?? []))),
            'stashes' => (int) ($data['stashes'] ?? 0),
            'last' => $last,
            'after' => array_values(array_map('strval', (array) ($data['after'] ?? []))),
            'squashed' => isset($data['squashed']) ? (string) $data['squashed'] : null,
        ];
    }

    /**
     * Start a terminal command in the workspace; the bridge checks it against its allowlist.
     *
     * @return array<string, mixed> the run: id, command, status, output…
     */
    public function run(string $command): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post('/run', ['command' => $command]));
    }

    /**
     * @return array<string, mixed>
     */
    public function runStatus(string $runId): array
    {
        return $this->request(fn (PendingRequest $http) => $http->get('/run/'.rawurlencode($runId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function killRun(string $runId): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post('/run/'.rawurlencode($runId).'/kill'));
    }

    /**
     * One directory of the workspace, folders first; `.git` is never listed.
     *
     * @return array{path: string, entries: list<array{name: string, type: string, size: int, mtime: ?string}>}
     */
    public function listFiles(string $path = ''): array
    {
        $data = $this->request(fn (PendingRequest $http) => $http->get('/files', ['path' => $path]));
        $entries = [];

        foreach ((array) ($data['entries'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['name'])) {
                continue;
            }

            $entries[] = [
                'name' => (string) $entry['name'],
                'type' => (string) ($entry['type'] ?? 'other'),
                'size' => (int) ($entry['size'] ?? 0),
                'mtime' => isset($entry['mtime']) ? (string) $entry['mtime'] : null,
            ];
        }

        return ['path' => (string) ($data['path'] ?? $path), 'entries' => $entries];
    }

    /**
     * A file's text with the hash of its bytes; binary or oversized files come back without content.
     *
     * @return array<string, mixed> path, size, binary, too_large, limit, content, hash
     */
    public function readFile(string $path): array
    {
        return $this->request(fn (PendingRequest $http) => $http->get('/files/read', ['path' => $path]));
    }

    /**
     * Write a file; with a hash the bridge refuses (409) when the file changed on disk meanwhile.
     *
     * @return array<string, mixed> path, size, hash
     */
    public function writeFile(string $path, string $content, ?string $hash = null): array
    {
        return $this->request(fn (PendingRequest $http) => $http->put('/files', ['path' => $path, 'content' => $content, 'hash' => $hash]));
    }

    /**
     * Any bytes into a file of the workspace, streamed (the drive's "copy to"):
     * missing folders are created, an existing file is replaced only with $overwrite.
     *
     * @param  resource  $stream
     * @return array<string, mixed> path, size
     */
    public function uploadFile(string $path, $stream, bool $overwrite = false): array
    {
        return $this->request(fn (PendingRequest $http) => $http
            ->timeout(600)
            ->withBody(Utils::streamFor($stream), 'application/octet-stream')
            ->withQueryParameters(['path' => $path, 'overwrite' => $overwrite ? '1' : '0'])
            ->put('/files/upload'));
    }

    /**
     * @param  'file'|'dir'  $type
     * @return array<string, mixed>
     */
    public function createPath(string $path, string $type = 'file'): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post('/files', ['path' => $path, 'type' => $type]));
    }

    /**
     * @return array<string, mixed>
     */
    public function renamePath(string $from, string $to): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post('/files/rename', ['from' => $from, 'to' => $to]));
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePath(string $path): array
    {
        return $this->request(fn (PendingRequest $http) => $http->withQueryParameters(['path' => $path])->delete('/files'));
    }

    /**
     * A zip of files and folders of one folder, next to them; the bridge picks a free name.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed> path, size
     */
    public function zipPaths(array $paths): array
    {
        return $this->request(fn (PendingRequest $http) => $http->timeout(600)->post('/files/zip', ['paths' => $paths]));
    }

    /**
     * Extract a zip next to it, into a new folder (or the one folder at its top).
     *
     * @return array<string, mixed> path
     */
    public function unzipPath(string $path): array
    {
        return $this->request(fn (PendingRequest $http) => $http->timeout(600)->post('/files/unzip', ['path' => $path]));
    }

    /**
     * A file's bytes as they come from the bridge, to stream on to the browser.
     *
     * @return array{stream: StreamInterface, size: ?int}
     */
    public function downloadFile(string $path): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->withOptions(['stream' => true])
            ->timeout(600)
            ->get('/files/download', ['path' => $path]));
        $size = $response->header('Content-Length');

        return ['stream' => $response->toPsrResponse()->getBody(), 'size' => $size === '' ? null : (int) $size];
    }

    /**
     * The project's MCP servers this person may use, for Claude Code in the
     * workspace: those that come with what is attached to the project (a Cipi
     * app's agent, its server), then those added in its settings, which win on
     * the same name. The repository's own .mcp.json still applies on top.
     *
     * @return array<string, array{type: string, url: string, headers: array<string, string>}>|null
     */
    public function mcpServers(Conversation $conversation): ?array
    {
        $project = $this->workspace->project;
        $servers = $project->mcpServers()
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (ProjectMcpServer $server) => $server->allows($conversation->user))
            ->mapWithKeys(fn (ProjectMcpServer $server) => [$server->name => $server->forBridge()])
            ->all();
        $servers = [...NativeServers::for($project, $conversation->user), ...$servers];

        return $servers === [] ? null : $servers;
    }

    public function callbackUrl(): string
    {
        $override = config('studio.bridge.callback_url');

        return filled($override) ? (string) $override : route('api.bridge.events');
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function request(callable $call): array
    {
        return (array) $this->send($call)->json();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        if (! $this->workspace->hasBridge()) {
            throw new BridgeException(__('The workspace has no bridge configured.'));
        }

        try {
            $response = $call($this->http())->throw();
        } catch (ConnectionException $e) {
            throw new BridgeException(__('The workspace bridge is not answering: :reason', ['reason' => $e->getMessage()]), previous: $e);
        } catch (RequestException $e) {
            $body = $e->response->json('error') ?? $e->response->body();

            throw new BridgeException(__('The bridge answered :status: :body', ['status' => $e->response->status(), 'body' => Str::limit((string) $body, 300)]), $e->response->status(), $e);
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) $this->workspace->bridge_url)
            ->withToken((string) $this->workspace->bridge_token)
            ->withHeaders($this->sandbox !== null ? ['X-Studio-Sandbox' => $this->sandbox] : [])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('studio.bridge.timeout', 15));
    }
}

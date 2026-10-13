<?php

namespace App\Bridge;

use App\Enums\ClaudeAuthMode;
use App\Models\Conversation;
use App\Models\PermissionRequest;
use App\Models\ProjectMcpServer;
use App\Models\Workspace;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Studio's side of the bridge protocol: plain HTTP calls into the Node bridge
 * that runs inside the workspace and drives Claude Code. Every turn tells the
 * bridge where to call back and with which token, so the bridge needs no
 * configuration about Studio beyond accepting this client's bearer token.
 */
final class BridgeClient
{
    public function __construct(private readonly Workspace $workspace) {}

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
        return $this->request(fn (PendingRequest $http) => $http->post("/conversations/{$conversation->id}/turns", [
            'text' => $text,
            'session_id' => $conversation->agent_session_id,
            'permission_mode' => $conversation->permission_mode,
            'model' => $conversation->model,
            'effort' => $conversation->effort,
            // always the account chosen in Settings → Connections, for every project and conversation:
            // even while it looks disconnected, so the CLI never falls back to another login or key
            'auth' => $conversation->user->claude_auth_mode === ClaudeAuthMode::None ? null : $conversation->user->claude_auth_mode->value,
            'mcp_servers' => $this->mcpServers($conversation),
            'callback' => [
                'url' => $this->callbackUrl(),
                'token' => $this->workspace->callback_token,
            ],
        ]));
    }

    /**
     * @param  array<string, string>|null  $answers  AskUserQuestion: question text → the person's answer
     * @return array<string, mixed>
     */
    public function answerPermission(PermissionRequest $request, bool $allow, ?string $message = null, ?array $answers = null): array
    {
        return $this->request(fn (PendingRequest $http) => $http->post(
            "/conversations/{$request->conversation_id}/permissions/{$request->request_id}",
            ['behavior' => $allow ? 'allow' : 'deny', 'message' => $message, ...($answers ? ['answers' => $answers] : [])],
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
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function git(): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->get('/git')));
    }

    /**
     * The newest commits of every branch, with parents, so the pane can draw the graph.
     *
     * @return array{branch: ?string, head: ?string, commits: list<array<string, mixed>>}
     */
    public function gitLog(int $limit = 80): array
    {
        $data = $this->request(fn (PendingRequest $http) => $http->get('/git/log', ['limit' => $limit]));

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
     * @return array<string, mixed> the commit with its stat and patch
     */
    public function gitCommit(string $sha): array
    {
        return $this->request(fn (PendingRequest $http) => $http->get('/git/commit/'.rawurlencode($sha)));
    }

    /**
     * Switch the working tree to a branch; the answer is the new working tree state.
     *
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitCheckout(string $branch): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/checkout', ['branch' => $branch])));
    }

    /**
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    public function gitFetch(): array
    {
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/git/fetch')));
    }

    /**
     * Stage everything and commit it; during a merge this concludes it.
     *
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitCommitAll(string $message): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/commit', ['message' => $message])));
    }

    /**
     * Throw away the changes of one path as git status lists it, or of the whole working tree.
     *
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitDiscard(?string $path = null): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/discard', ['path' => $path])));
    }

    /**
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitPull(): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/git/pull')));
    }

    /**
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitPush(): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->timeout(120)->post('/git/push')));
    }

    /**
     * Merge a branch into the current one; a conflict leaves the merge open (the state says `merging`).
     *
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitMerge(string $branch): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/merge', ['branch' => $branch])));
    }

    /**
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitMergeAbort(): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/merge/abort')));
    }

    /**
     * Create a branch from the current commit, or from $from, and switch to it.
     *
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitCreateBranch(string $branch, ?string $from = null): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/branches', ['branch' => $branch, 'from' => $from])));
    }

    /**
     * @return array{current: ?string, local: list<array<string, mixed>>, remote: list<array<string, mixed>>}
     */
    public function gitDeleteBranch(string $branch): array
    {
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->withQueryParameters(['branch' => $branch])->delete('/git/branches')));
    }

    /**
     * A new commit that undoes $sha.
     *
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitRevert(string $sha): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/revert', ['sha' => $sha])));
    }

    /**
     * Put the changes aside, or bring the newest stash back with $pop.
     *
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    public function gitStash(bool $pop = false): array
    {
        return $this->stateFrom($this->request(fn (PendingRequest $http) => $http->post('/git/stash', ['pop' => $pop])));
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
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string, upstream: ?string, ahead: int, behind: int, merging: bool, conflicts: list<string>, stashes: int}
     */
    private function stateFrom(array $data): array
    {
        return [
            'branch' => isset($data['branch']) ? (string) $data['branch'] : null,
            'status' => array_values(array_map('strval', (array) ($data['status'] ?? []))),
            'stat' => (string) ($data['stat'] ?? ''),
            'diff' => (string) ($data['diff'] ?? ''),
            'upstream' => isset($data['upstream']) ? (string) $data['upstream'] : null,
            'ahead' => (int) ($data['ahead'] ?? 0),
            'behind' => (int) ($data['behind'] ?? 0),
            'merging' => (bool) ($data['merging'] ?? false),
            'conflicts' => array_values(array_map('strval', (array) ($data['conflicts'] ?? []))),
            'stashes' => (int) ($data['stashes'] ?? 0),
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
     * The project's MCP servers this person may use, for Claude Code in the
     * workspace. The repository's own .mcp.json still applies on top.
     *
     * @return array<string, array{type: string, url: string, headers: array<string, string>}>|null
     */
    public function mcpServers(Conversation $conversation): ?array
    {
        $servers = $this->workspace->project->mcpServers()
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (ProjectMcpServer $server) => $server->allows($conversation->user))
            ->mapWithKeys(fn (ProjectMcpServer $server) => [$server->name => $server->forBridge()])
            ->all();

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
        if (! $this->workspace->hasBridge()) {
            throw new BridgeException(__('The workspace has no bridge configured.'));
        }

        try {
            $response = $call($this->http())->throw();
        } catch (ConnectionException $e) {
            throw new BridgeException(__('The workspace bridge is not answering: :reason', ['reason' => $e->getMessage()]), previous: $e);
        } catch (RequestException $e) {
            $body = $e->response->json('error') ?? $e->response->body();

            throw new BridgeException(__('The bridge answered :status: :body', ['status' => $e->response->status(), 'body' => Str::limit((string) $body, 300)]), previous: $e);
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) $this->workspace->bridge_url)
            ->withToken((string) $this->workspace->bridge_token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('studio.bridge.timeout', 15));
    }
}

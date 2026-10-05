<?php

namespace App\Bridge;

use App\Models\Conversation;
use App\Models\PermissionRequest;
use App\Models\ProjectMcpServer;
use App\Models\Workspace;
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
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string}
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
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string}
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
        return $this->branchesFrom($this->request(fn (PendingRequest $http) => $http->post('/git/fetch')));
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
     * @return array{branch: ?string, status: list<string>, stat: string, diff: string}
     */
    private function stateFrom(array $data): array
    {
        return [
            'branch' => isset($data['branch']) ? (string) $data['branch'] : null,
            'status' => array_values(array_map('strval', (array) ($data['status'] ?? []))),
            'stat' => (string) ($data['stat'] ?? ''),
            'diff' => (string) ($data['diff'] ?? ''),
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

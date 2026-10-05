<?php

namespace App\Git\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bitbucket Cloud, with an Atlassian API token (scoped, Bearer): git signs in as
 * `x-bitbucket-api-token-auth`, Larapilot reads BITBUCKET_ACCESS_TOKEN and talks
 * to the REST API. There is no CLI to install.
 */
final class Bitbucket extends GitProvider
{
    private const API = 'https://api.bitbucket.org/2.0';

    /** How many workspaces get their projects listed in the new-repository form. */
    private const WORKSPACES_WITH_PROJECTS = 10;

    public function key(): string
    {
        return 'bitbucket';
    }

    public static function name(): string
    {
        return 'Bitbucket';
    }

    public static function defaultUrl(): string
    {
        return 'https://bitbucket.org';
    }

    public function cli(): ?string
    {
        return null;
    }

    public function repositoryExample(): string
    {
        return 'https://bitbucket.org/workspace/repo';
    }

    public function tokenPlaceholder(): string
    {
        return 'ATATT…';
    }

    public function ownerLabel(): string
    {
        return __('Workspace / project');
    }

    public function hasPersonalNamespace(): bool
    {
        return false;
    }

    public function repositoryPath(string $url): ?string
    {
        $path = $this->pathOf($url, 'bitbucket.org');

        return $path !== null && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $path) ? $path : null;
    }

    public function whoAmI(string $token): array
    {
        $user = $this->call(fn () => $this->http($token)->get('/user'), __('read the account'));
        $login = is_array($user) ? ($user['username'] ?? $user['nickname'] ?? null) : null;

        if (! is_string($login) || $login === '') {
            throw new RuntimeException(__(':provider did not return a user for this token.', ['provider' => $this->label()]));
        }

        return ['login' => $login, 'name' => isset($user['display_name']) ? (string) $user['display_name'] : null];
    }

    /**
     * Each workspace the person belongs to (its repositories land in the workspace's
     * oldest project) and each project of it: `workspace` or `workspace/PROJECTKEY`.
     */
    public function owners(string $token): array
    {
        try {
            $page = $this->http($token)->get('/user/workspaces', ['pagelen' => 100, 'sort' => 'slug'])->throw()->json();
        } catch (ConnectionException|RequestException) {
            return [];
        }

        $owners = [];

        foreach (array_slice(is_array($page['values'] ?? null) ? $page['values'] : [], 0, 50) as $i => $access) {
            $slug = data_get($access, 'workspace.slug');

            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $owners[$slug] = (string) (data_get($access, 'workspace.name') ?: $slug);

            if ($i >= self::WORKSPACES_WITH_PROJECTS) {
                continue;
            }

            try {
                $projects = $this->http($token)->get("/workspaces/{$slug}/projects", ['pagelen' => 100, 'sort' => 'name'])->throw()->json();
            } catch (ConnectionException|RequestException) {
                continue;
            }

            foreach (is_array($projects['values'] ?? null) ? $projects['values'] : [] as $project) {
                if (is_array($project) && isset($project['key'])) {
                    $owners[$slug.'/'.$project['key']] = $owners[$slug].' / '.($project['name'] ?? $project['key']);
                }
            }
        }

        return $owners;
    }

    public function createRepository(string $token, ?string $owner, string $name, bool $private = true): array
    {
        if (! $owner) {
            throw new RuntimeException(__('Choose the workspace of the new repository.'));
        }

        [$workspace, $projectKey] = array_pad(explode('/', $owner, 2), 2, null);
        $slug = Str::slug($name);

        $repo = $this->call(fn () => $this->http($token)->post("/repositories/{$workspace}/{$slug}", array_filter([
            'scm' => 'git',
            'name' => $name,
            'is_private' => $private,
            'project' => $projectKey ? ['key' => $projectKey] : null,
        ], fn ($value) => $value !== null)), __('create the repository'));

        if (! is_array($repo) || ! isset($repo['full_name'])) {
            throw new RuntimeException(__(':provider did not return the new repository.', ['provider' => $this->label()]));
        }

        return [
            'full_name' => (string) $repo['full_name'],
            'clone_url' => 'https://bitbucket.org/'.$repo['full_name'].'.git',
            'html_url' => (string) data_get($repo, 'links.html.href', 'https://bitbucket.org/'.$repo['full_name']),
            'default_branch' => (string) (data_get($repo, 'mainbranch.name') ?: 'main'),
        ];
    }

    public function createPushWebhook(string $token, string $repository, string $url, string $secret, string $branch): string
    {
        $hook = $this->call(fn () => $this->http($token)->post("/repositories/{$repository}/hooks", [
            'description' => 'Studio: deploy '.$branch,
            'url' => $url,
            'active' => true,
            'secret' => $secret,
            'events' => ['repo:push'],
        ]), __('register the push webhook'));

        return (string) (is_array($hook) ? ($hook['uuid'] ?? '') : '');
    }

    public function deleteWebhook(string $token, string $repository, string $hookId): void
    {
        try {
            $this->http($token)->delete("/repositories/{$repository}/hooks/".rawurlencode($hookId))->throw();
        } catch (ConnectionException|RequestException) {
            // A hook that is already gone is fine.
        }
    }

    /** With a secret set, Bitbucket signs the body: X-Hub-Signature: sha256=<hmac>. */
    public function verifyWebhook(Request $request, string $secret): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, (string) $request->header('X-Hub-Signature', ''));
    }

    public function webhookEvent(Request $request): WebhookEvent
    {
        return match ((string) $request->header('X-Event-Key', '')) {
            'diagnostics:ping' => WebhookEvent::ping(),
            'repo:push' => WebhookEvent::push(array_values(array_filter(array_map(
                fn ($change) => data_get($change, 'new.type') === 'branch' && is_string(data_get($change, 'new.name')) ? (string) data_get($change, 'new.name') : null,
                (array) $request->input('push.changes', []),
            )))),
            default => WebhookEvent::ignored(),
        };
    }

    public function gitUsername(): string
    {
        return 'x-bitbucket-api-token-auth';
    }

    public function agentEnvironment(string $token): array
    {
        return ['BITBUCKET_ACCESS_TOKEN' => $token];
    }

    private function http(string $token): PendingRequest
    {
        return $this->timeouts(Http::baseUrl(self::API)->withToken($token)->acceptJson());
    }
}

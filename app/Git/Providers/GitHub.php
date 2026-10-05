<?php

namespace App\Git\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** github.com, with a personal access token; the agent uses `gh`. */
final class GitHub extends GitProvider
{
    public function key(): string
    {
        return 'github';
    }

    public static function name(): string
    {
        return 'GitHub';
    }

    public static function defaultUrl(): string
    {
        return 'https://github.com';
    }

    public function cli(): string
    {
        return 'gh';
    }

    public function repositoryExample(): string
    {
        return 'https://github.com/owner/repo';
    }

    public function tokenPlaceholder(): string
    {
        return 'github_pat_…';
    }

    public function ownerLabel(): string
    {
        return __('Organization');
    }

    public function hasPersonalNamespace(): bool
    {
        return true;
    }

    public function repositoryPath(string $url): ?string
    {
        $path = $this->pathOf($url, 'github.com');

        return $path !== null && preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $path) ? $path : null;
    }

    public function whoAmI(string $token): array
    {
        $user = $this->call(fn () => $this->http($token)->get('/user'), __('read the account'));

        if (! is_array($user) || ! isset($user['login'])) {
            throw new RuntimeException(__(':provider did not return a user for this token.', ['provider' => $this->label()]));
        }

        return ['login' => (string) $user['login'], 'name' => isset($user['name']) ? (string) $user['name'] : null];
    }

    public function owners(string $token): array
    {
        try {
            $orgs = $this->http($token)->get('/user/orgs', ['per_page' => 100])->throw()->json();
        } catch (ConnectionException|RequestException) {
            return [];
        }

        $owners = [];

        foreach (is_array($orgs) ? $orgs : [] as $org) {
            if (is_array($org) && is_string($org['login'] ?? null)) {
                $owners[$org['login']] = $org['login'];
            }
        }

        return $owners;
    }

    public function createRepository(string $token, ?string $owner, string $name, bool $private = true): array
    {
        $repo = $this->call(fn () => $this->http($token)->post($owner ? "/orgs/{$owner}/repos" : '/user/repos', [
            'name' => $name,
            'private' => $private,
            'auto_init' => false,
            'has_wiki' => false,
            'has_projects' => false,
        ]), __('create the repository'));

        if (! is_array($repo) || ! isset($repo['clone_url'])) {
            throw new RuntimeException(__(':provider did not return the new repository.', ['provider' => $this->label()]));
        }

        return [
            'full_name' => (string) $repo['full_name'],
            'clone_url' => (string) $repo['clone_url'],
            'html_url' => (string) ($repo['html_url'] ?? ''),
            'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
        ];
    }

    public function createPushWebhook(string $token, string $repository, string $url, string $secret, string $branch): string
    {
        $hook = $this->call(fn () => $this->http($token)->post("/repos/{$repository}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]), __('register the push webhook'));

        return (string) (is_array($hook) ? ($hook['id'] ?? '') : '');
    }

    public function deleteWebhook(string $token, string $repository, string $hookId): void
    {
        try {
            $this->http($token)->delete("/repos/{$repository}/hooks/{$hookId}")->throw();
        } catch (ConnectionException|RequestException) {
            // A hook that is already gone is fine.
        }
    }

    public function verifyWebhook(Request $request, string $secret): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, (string) $request->header('X-Hub-Signature-256', ''));
    }

    public function webhookEvent(Request $request): WebhookEvent
    {
        return match ((string) $request->header('X-GitHub-Event', '')) {
            'ping' => WebhookEvent::ping(),
            'push' => WebhookEvent::push(array_filter([WebhookEvent::branchOf($request->input('ref'))])),
            default => WebhookEvent::ignored(),
        };
    }

    public function gitUsername(): string
    {
        return 'x-access-token';
    }

    public function agentEnvironment(string $token): array
    {
        return ['GITHUB_TOKEN' => $token, 'GH_TOKEN' => $token];
    }

    private function http(string $token): PendingRequest
    {
        return $this->timeouts(Http::baseUrl('https://api.github.com')
            ->withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']));
    }
}

<?php

namespace App\Git\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** gitlab.com or a self-managed GitLab, with a personal access token; the agent uses `glab`. */
final class GitLab extends GitProvider
{
    public function key(): string
    {
        return 'gitlab';
    }

    public static function name(): string
    {
        return 'GitLab';
    }

    public static function defaultUrl(): string
    {
        return 'https://gitlab.com';
    }

    public function cli(): string
    {
        return 'glab';
    }

    public function repositoryExample(): string
    {
        return $this->baseUrl.'/group/repo';
    }

    public function tokenPlaceholder(): string
    {
        return 'glpat-…';
    }

    public function ownerLabel(): string
    {
        return __('Group');
    }

    public function hasPersonalNamespace(): bool
    {
        return true;
    }

    public function repositoryPath(string $url): ?string
    {
        $path = $this->pathOf($url, $this->host());

        // group[/subgroup…]/project, never one of GitLab's own `/-/` pages
        return $path !== null && preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_.-]*(/[A-Za-z0-9_.][A-Za-z0-9_.-]*)+$#', $path) ? $path : null;
    }

    public function whoAmI(string $token): array
    {
        $user = $this->call(fn () => $this->http($token)->get('/user'), __('read the account'));

        if (! is_array($user) || ! isset($user['username'])) {
            throw new RuntimeException(__(':provider did not return a user for this token.', ['provider' => $this->label()]));
        }

        return ['login' => (string) $user['username'], 'name' => isset($user['name']) ? (string) $user['name'] : null];
    }

    /** Groups and subgroups where the person is at least a Developer; the value is the namespace id. */
    public function owners(string $token): array
    {
        try {
            $groups = $this->http($token)->get('/groups', ['min_access_level' => 30, 'per_page' => 100, 'order_by' => 'path', 'sort' => 'asc'])->throw()->json();
        } catch (ConnectionException|RequestException) {
            return [];
        }

        $owners = [];

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_array($group) && isset($group['id'], $group['full_path'])) {
                $owners[(string) $group['id']] = (string) $group['full_path'];
            }
        }

        return $owners;
    }

    public function createRepository(string $token, ?string $owner, string $name, bool $private = true): array
    {
        $project = $this->call(fn () => $this->http($token)->post('/projects', array_filter([
            'name' => $name,
            'path' => Str::slug($name),
            'namespace_id' => $owner ? (int) $owner : null,
            'visibility' => $private ? 'private' : 'public',
            'initialize_with_readme' => false,
        ], fn ($value) => $value !== null)), __('create the repository'));

        if (! is_array($project) || ! isset($project['http_url_to_repo'])) {
            throw new RuntimeException(__(':provider did not return the new repository.', ['provider' => $this->label()]));
        }

        return [
            'full_name' => (string) $project['path_with_namespace'],
            'clone_url' => (string) $project['http_url_to_repo'],
            'html_url' => (string) ($project['web_url'] ?? ''),
            'default_branch' => (string) ($project['default_branch'] ?? 'main') ?: 'main',
        ];
    }

    public function createPushWebhook(string $token, string $repository, string $url, string $secret, string $branch): string
    {
        $hook = $this->call(fn () => $this->http($token)->post('/projects/'.rawurlencode($repository).'/hooks', [
            'url' => $url,
            'name' => 'Studio',
            'push_events' => true,
            'push_events_branch_filter' => $branch,
            'merge_requests_events' => false,
            'tag_push_events' => false,
            'token' => $secret,
            'enable_ssl_verification' => true,
        ]), __('register the push webhook'));

        return (string) (is_array($hook) ? ($hook['id'] ?? '') : '');
    }

    public function deleteWebhook(string $token, string $repository, string $hookId): void
    {
        try {
            $this->http($token)->delete('/projects/'.rawurlencode($repository).'/hooks/'.rawurlencode($hookId))->throw();
        } catch (ConnectionException|RequestException) {
            // A hook that is already gone is fine.
        }
    }

    /** GitLab sends the secret token back as it is, in X-Gitlab-Token. */
    public function verifyWebhook(Request $request, string $secret): bool
    {
        return hash_equals($secret, (string) $request->header('X-Gitlab-Token', ''));
    }

    public function webhookEvent(Request $request): WebhookEvent
    {
        return $request->header('X-Gitlab-Event') === 'Push Hook'
            ? WebhookEvent::push(array_filter([WebhookEvent::branchOf($request->input('ref'))]))
            : WebhookEvent::ignored();
    }

    public function gitUsername(): string
    {
        return 'oauth2';
    }

    public function agentEnvironment(string $token): array
    {
        $env = ['GITLAB_TOKEN' => $token, 'GLAB_TOKEN' => $token];

        // glab talks to gitlab.com unless told otherwise
        if ($this->host() !== 'gitlab.com') {
            $env['GITLAB_HOST'] = $this->baseUrl;
        }

        return $env;
    }

    private function http(string $token): PendingRequest
    {
        return $this->timeouts(Http::baseUrl($this->baseUrl.'/api/v4')->withHeaders(['PRIVATE-TOKEN' => $token, 'Accept' => 'application/json']));
    }
}

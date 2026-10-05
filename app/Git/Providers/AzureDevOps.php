<?php

namespace App\Git\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Azure DevOps Services, one organisation per installation
 * (STUDIO_GIT_URL=https://dev.azure.com/<organization>), with a personal access
 * token; the agent uses `az repos` from the azure-devops extension.
 * Repositories live in the organisation's projects.
 */
final class AzureDevOps extends GitProvider
{
    private const VERSION = '7.1';

    public function __construct(string $baseUrl)
    {
        // https://<org>.visualstudio.com is the old address of https://dev.azure.com/<org>
        if (preg_match('#^https://([a-z0-9-]+)\.visualstudio\.com#i', $baseUrl, $m)) {
            $baseUrl = 'https://dev.azure.com/'.$m[1];
        }

        parent::__construct($baseUrl);
    }

    public function key(): string
    {
        return 'azure';
    }

    public static function name(): string
    {
        return 'Azure DevOps';
    }

    public static function defaultUrl(): string
    {
        return 'https://dev.azure.com';
    }

    public function cli(): string
    {
        return 'az';
    }

    public function organization(): string
    {
        $organization = explode('/', trim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/'))[0];

        if ($organization === '') {
            throw new RuntimeException(__('Azure DevOps needs the organization: set STUDIO_GIT_URL to https://dev.azure.com/<organization>.'));
        }

        return $organization;
    }

    public function repositoryExample(): string
    {
        return 'https://dev.azure.com/'.($this->organizationOrNull() ?? 'organization').'/project/_git/repo';
    }

    public function tokenPlaceholder(): string
    {
        return __('personal access token');
    }

    public function ownerLabel(): string
    {
        return __('Project');
    }

    public function hasPersonalNamespace(): bool
    {
        return false;
    }

    /** `organization/project/repo`, only for repositories of this installation's organisation. */
    public function repositoryPath(string $url): ?string
    {
        $url = trim($url);

        if (preg_match('#^https://(?:[^@/\s]+@)?dev\.azure\.com/([^/\s]+)/([^/\s]+)/_git/([^/\s?\#]+?)/?$#i', $url, $m)) {
            [$organization, $project, $repo] = [$m[1], $m[2], $m[3]];
        } elseif (preg_match('#^https://(?:[^@/\s]+@)?([a-z0-9-]+)\.visualstudio\.com/(?:DefaultCollection/)?([^/\s]+)/_git/([^/\s?\#]+?)/?$#i', $url, $m)) {
            [$organization, $project, $repo] = [$m[1], $m[2], $m[3]];
        } else {
            return null;
        }

        if (strcasecmp(rawurldecode($organization), (string) $this->organizationOrNull()) !== 0) {
            return null;
        }

        return implode('/', array_map('rawurldecode', [$organization, $project, $repo]));
    }

    public function whoAmI(string $token): array
    {
        // connectionData answers for any valid token of the organisation, whatever its scopes; it takes no api-version.
        $data = $this->call(fn () => $this->http($token, versioned: false)->get('/_apis/connectionData'), __('read the account'));
        $account = data_get($data, 'authenticatedUser.properties.Account.$value');

        if (! is_string($account) || $account === '') {
            throw new RuntimeException(__(':provider did not return a user for this token: check that it belongs to the :organization organization.', ['provider' => $this->label(), 'organization' => $this->organization()]));
        }

        $name = data_get($data, 'authenticatedUser.providerDisplayName');

        return ['login' => $account, 'name' => is_string($name) ? $name : null];
    }

    /** The organisation's projects: a repository always belongs to one. */
    public function owners(string $token): array
    {
        try {
            $projects = $this->http($token)->get('/_apis/projects', ['$top' => 500])->throw()->json();
        } catch (ConnectionException|RequestException) {
            return [];
        }

        $owners = [];

        foreach (is_array($projects['value'] ?? null) ? $projects['value'] : [] as $project) {
            if (is_array($project) && is_string($project['name'] ?? null)) {
                $owners[$project['name']] = $project['name'];
            }
        }

        ksort($owners, SORT_NATURAL | SORT_FLAG_CASE);

        return $owners;
    }

    /** Azure DevOps repositories follow the visibility of their project, so $private does not apply. */
    public function createRepository(string $token, ?string $owner, string $name, bool $private = true): array
    {
        if (! $owner) {
            throw new RuntimeException(__('Choose the project of the new repository.'));
        }

        $project = $this->call(fn () => $this->http($token)->get('/_apis/projects/'.rawurlencode($owner)), __('read the project'));
        $repo = $this->call(fn () => $this->http($token)->post('/_apis/git/repositories', [
            'name' => $name,
            'project' => ['id' => data_get($project, 'id')],
        ]), __('create the repository'));

        if (! is_array($repo) || ! isset($repo['remoteUrl'])) {
            throw new RuntimeException(__(':provider did not return the new repository.', ['provider' => $this->label()]));
        }

        return [
            'full_name' => $this->organization().'/'.$owner.'/'.$repo['name'],
            'clone_url' => (string) preg_replace('#^https://[^@/]+@#', 'https://', (string) $repo['remoteUrl']),
            'html_url' => (string) ($repo['webUrl'] ?? ''),
            'default_branch' => 'main',
        ];
    }

    /** A service hook subscription on git.push for the repository; deliveries carry the secret in X-Studio-Token. */
    public function createPushWebhook(string $token, string $repository, string $url, string $secret, string $branch): string
    {
        [, $project, $name] = explode('/', $repository, 3);

        $repo = $this->call(fn () => $this->http($token)->get('/'.rawurlencode($project).'/_apis/git/repositories/'.rawurlencode($name)), __('read the repository'));

        $subscription = $this->call(fn () => $this->http($token)->post('/_apis/hooks/subscriptions', [
            'publisherId' => 'tfs',
            'eventType' => 'git.push',
            'resourceVersion' => '1.0',
            'consumerId' => 'webHooks',
            'consumerActionId' => 'httpRequest',
            'publisherInputs' => [
                'projectId' => (string) data_get($repo, 'project.id'),
                'repository' => (string) data_get($repo, 'id'),
                'branch' => '',
                'pushedBy' => '',
            ],
            'consumerInputs' => [
                'url' => $url,
                'httpHeaders' => 'X-Studio-Token:'.$secret,
                'resourceDetailsToSend' => 'all',
                'messagesToSend' => 'none',
                'detailedMessagesToSend' => 'none',
            ],
        ]), __('register the push webhook'));

        return (string) (is_array($subscription) ? ($subscription['id'] ?? '') : '');
    }

    public function deleteWebhook(string $token, string $repository, string $hookId): void
    {
        try {
            $this->http($token)->delete('/_apis/hooks/subscriptions/'.rawurlencode($hookId))->throw();
        } catch (ConnectionException|RequestException) {
            // A subscription that is already gone is fine.
        }
    }

    public function verifyWebhook(Request $request, string $secret): bool
    {
        return hash_equals($secret, (string) $request->header('X-Studio-Token', ''));
    }

    public function webhookEvent(Request $request): WebhookEvent
    {
        if ($request->input('eventType') !== 'git.push') {
            return WebhookEvent::ignored();
        }

        return WebhookEvent::push(array_values(array_filter(array_map(
            fn ($update) => WebhookEvent::branchOf(data_get($update, 'name')),
            (array) $request->input('resource.refUpdates', []),
        ))));
    }

    public function gitUsername(): string
    {
        // Azure DevOps ignores the user name when the password is a personal access token.
        return 'studio';
    }

    public function agentEnvironment(string $token): array
    {
        return ['AZURE_DEVOPS_EXT_PAT' => $token];
    }

    private function organizationOrNull(): ?string
    {
        try {
            return $this->organization();
        } catch (RuntimeException) {
            return null;
        }
    }

    private function http(string $token, bool $versioned = true): PendingRequest
    {
        $request = Http::baseUrl('https://dev.azure.com/'.rawurlencode($this->organization()))->withBasicAuth('', $token)->acceptJson();

        return $this->timeouts($versioned ? $request->withQueryParameters(['api-version' => self::VERSION]) : $request);
    }
}

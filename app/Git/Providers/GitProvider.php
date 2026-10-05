<?php

namespace App\Git\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The remote forge of this Studio installation: GitHub, GitLab, Bitbucket
 * Cloud or Azure DevOps, the same four Larapilot integrates with. One per
 * installation; every project lives there and every person connects to it.
 *
 * Studio needs little from it: who a token belongs to, where the token may
 * create repositories, the repository itself, and the push webhook that
 * deploys a project site. Git, and the agent's CLI, do everything else.
 */
abstract class GitProvider
{
    /** Every provider Studio supports, by key: the same ones Larapilot does. */
    public const PROVIDERS = [
        'github' => GitHub::class,
        'gitlab' => GitLab::class,
        'bitbucket' => Bitbucket::class,
        'azure' => AzureDevOps::class,
    ];

    public function __construct(protected readonly string $baseUrl) {}

    /** The provider configured for this installation. */
    public static function current(): self
    {
        return self::make((string) config('studio.git.provider', 'github'), config('studio.git.url'));
    }

    public static function make(string $key, mixed $url = null): self
    {
        $class = self::PROVIDERS[$key] ?? throw new RuntimeException(__('Unknown git provider ":key". Use one of: :keys.', ['key' => $key, 'keys' => implode(', ', array_keys(self::PROVIDERS))]));
        $url = is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : $class::defaultUrl();

        return new $class($url);
    }

    /** @return array<string, string> key => label */
    public static function labels(): array
    {
        return array_map(fn (string $class) => $class::name(), self::PROVIDERS);
    }

    /** github, gitlab, bitbucket or azure: also the Larapilot setting that turns the integration on. */
    abstract public function key(): string;

    abstract public static function name(): string;

    /** The web address of the forge (or of the organisation, for Azure DevOps). */
    abstract public static function defaultUrl(): string;

    public function label(): string
    {
        return static::name();
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The command-line tool the agent uses for pull requests, installed on the server; null when Larapilot talks to the REST API. */
    abstract public function cli(): ?string;

    /** What a repository address looks like here, for placeholders and errors. */
    abstract public function repositoryExample(): string;

    abstract public function tokenPlaceholder(): string;

    /** What the owner of a new repository is called here: organisation, group, workspace, project. */
    abstract public function ownerLabel(): string;

    /** Whether a person can own repositories directly, outside any organisation. */
    abstract public function hasPersonalNamespace(): bool;

    /**
     * The repository path (`owner/repo`, `group/sub/repo`, `org/project/repo`) of an
     * HTTPS address on this forge, or null when the address belongs somewhere else.
     */
    abstract public function repositoryPath(string $url): ?string;

    /**
     * @return array{login: string, name: ?string}
     */
    abstract public function whoAmI(string $token): array;

    /**
     * Places the token may create repositories in besides the person's own namespace.
     *
     * @return array<string, string> value => label
     */
    abstract public function owners(string $token): array;

    /**
     * Create an empty repository, owned by the person (null owner) or by one of owners().
     *
     * @return array{full_name: string, clone_url: string, html_url: string, default_branch: string}
     */
    abstract public function createRepository(string $token, ?string $owner, string $name, bool $private = true): array;

    /** Register a push webhook on the repository; returns the forge's id for it. */
    abstract public function createPushWebhook(string $token, string $repository, string $url, string $secret, string $branch): string;

    abstract public function deleteWebhook(string $token, string $repository, string $hookId): void;

    /** Whether a webhook delivery carries the project's secret. */
    abstract public function verifyWebhook(Request $request, string $secret): bool;

    abstract public function webhookEvent(Request $request): WebhookEvent;

    /** The user name git sends over HTTPS together with the token. */
    abstract public function gitUsername(): string;

    /**
     * Environment of a workspace, so the agent's CLI and Larapilot's integration find the token.
     *
     * @return array<string, string>
     */
    abstract public function agentEnvironment(string $token): array;

    /** Every variable agentEnvironment() may set, on any provider: the helper clears the others. */
    public const AGENT_VARIABLES = ['GITHUB_TOKEN', 'GH_TOKEN', 'GITLAB_TOKEN', 'GLAB_TOKEN', 'GITLAB_HOST', 'BITBUCKET_ACCESS_TOKEN', 'AZURE_DEVOPS_EXT_PAT'];

    protected function host(): string
    {
        return (string) parse_url($this->baseUrl, PHP_URL_HOST);
    }

    /** Strip `user@` and a trailing `.git` or slash from an HTTPS address and split its path. */
    protected function pathOf(string $url, string $host): ?string
    {
        $url = trim($url);

        if (! preg_match('~^https://(?:[^@/\s]+@)?([^/\s]+)(/[^\s?#]*)$~i', $url, $m) || strcasecmp($m[1], $host) !== 0) {
            return null;
        }

        $path = trim((string) preg_replace('#\.git/?$#i', '', rtrim($m[2], '/')), '/');

        return $path !== '' ? $path : null;
    }

    /** Run one API call, turning transport and HTTP failures into a message a person can act on. */
    protected function call(callable $request, string $action): mixed
    {
        try {
            return $request()->throw()->json();
        } catch (ConnectionException $e) {
            throw new RuntimeException(__(':provider is not reachable: :reason', ['provider' => $this->label(), 'reason' => $e->getMessage()]), previous: $e);
        } catch (RequestException $e) {
            $status = $e->response->status();
            $reason = $this->errorMessage($e) ?: (string) $status;

            throw new RuntimeException(match (true) {
                $status === 401 => __(':provider does not recognise this token.', ['provider' => $this->label()]),
                $status === 403 => __(':provider refused to :action: the token lacks a permission (:reason).', ['provider' => $this->label(), 'action' => $action, 'reason' => $reason]),
                default => __(':provider could not :action: :reason', ['provider' => $this->label(), 'action' => $action, 'reason' => $reason]),
            }, previous: $e);
        }
    }

    protected function errorMessage(RequestException $e): string
    {
        $json = $e->response->json();

        if (! is_array($json)) {
            return '';
        }

        foreach (['errors.0.message', 'message', 'error.message', 'error_description', 'error'] as $key) {
            $value = data_get($json, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value) && $value !== []) {
                return (string) json_encode($value);
            }
        }

        return '';
    }

    protected function timeouts(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders(['User-Agent' => 'studio'])->connectTimeout(5)->timeout(15);
    }
}

<?php

namespace App\Git;

use App\Git\Providers\GitProvider;
use App\Models\ConnectorAccount;

/**
 * A person's account on a git host as git and the forge's API use it: the
 * token, the user name git sends with it over HTTPS, the identity its commits
 * carry, and the provider of its host (github.com, a self-managed GitLab, an
 * Azure DevOps organization) for repositories and webhooks.
 */
final readonly class GitAccount
{
    /** @var array{provider: string, token: string, username: string, login: string, name: string, email: string, host: string} */
    private array $credentials;

    public function __construct(public ConnectorAccount $account)
    {
        $this->credentials = $account->gitCredentials();
    }

    public function provider(): GitProvider
    {
        return GitProvider::make($this->credentials['provider'], 'https://'.$this->credentials['host']);
    }

    public function token(): string
    {
        return $this->credentials['token'];
    }

    public function username(): string
    {
        return $this->credentials['username'];
    }

    public function login(): string
    {
        return $this->credentials['login'];
    }

    public function name(): string
    {
        return $this->credentials['name'];
    }

    public function email(): string
    {
        return $this->credentials['email'];
    }

    public function host(): string
    {
        return $this->credentials['host'];
    }

    /** "Ada Lovelace <ada@acme.com>", as git writes it. */
    public function identity(): string
    {
        return $this->name().' <'.$this->email().'>';
    }

    /**
     * What a workspace needs to work with this account: the token and the name git sends
     * with it, the variables the agent's CLI reads (gh, glab, az), the commit identity.
     *
     * @return array{git_token: string, git_username: string, git_env: array<string, string>|object, git_name: string, git_email: string}
     */
    public function workspaceInput(): array
    {
        $env = $this->provider()->agentEnvironment($this->token());

        return [
            'git_token' => $this->token(),
            'git_username' => $this->username(),
            'git_env' => $env === [] ? (object) [] : $env,
            'git_name' => $this->name(),
            'git_email' => $this->email(),
        ];
    }
}
